<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

use AlliancePay\AlliancePayHelper;
use AlliancePay\Sdk\Payment\Dto\Subscription\Plan\PlanRequestDTO;
use AlliancePay\Sdk\Payment\Dto\Subscription\Plan\PlanStatusUpdateRequestDTO;
use AlliancePay\Sdk\Payment\Dto\Subscription\Plan\PlanVersionUpdateRequestDTO;
use AlliancePay\Sdk\Payment\Subscription\Plan\PlanLifecycleOrchestrator;
use AlliancePay\Sdk\Payment\Subscription\Plan\UpdatePlanStatus;
use AlliancePay\Sdk\Payment\Subscription\Plan\UpdatePlanVersion;
use AlliancePay\Sdk\Services\Authorization\Dto\AuthorizationDTO;
use WHMCS\Database\Capsule;

/**
 * Class PlanService.
 */
class PlanService
{
    private const NAME_MAX = 255;
    private const DESCRIPTION_MAX = 1000;
    private const CATEGORY_MAX = 50;

    /**
     * @param array $gatewayParams
     * @param array $input
     * @param int|null $adminId
     * @return array{ok: bool, plan_id: int|null, errors: string[], warning: string|null}
     */
    public static function create(array $gatewayParams, array $input, ?int $adminId): array
    {
        $productId = (int)($input['product_id'] ?? 0);
        $billingCycle = (string)($input['billing_cycle'] ?? '');
        $category = trim((string)($input['category_indicator'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $merchantId = (string)($gatewayParams['merchantId'] ?? '');

        $errors = [];
        $product = $productId ? Capsule::table('tblproducts')->where('id', $productId)->first() : null;
        if (!$product) {
            $errors[] = 'Оберіть товар.';
        }
        if (!RuleBuilder::isSupportedCycle($billingCycle)) {
            $errors[] = 'Оберіть billing cycle.';
        }
        if ($merchantId === '') {
            $errors[] = 'У налаштуваннях шлюзу не вказано Merchant ID.';
        }
        if ($category === '' || mb_strlen($category) > self::CATEGORY_MAX) {
            $errors[] = 'Category indicator обов\'язковий (до ' . self::CATEGORY_MAX . ' символів).';
        }
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            $errors[] = 'Назва плану обов\'язкова (до ' . self::NAME_MAX . ' символів).';
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $errors[] = 'Опис задовгий (до ' . self::DESCRIPTION_MAX . ' символів).';
        }
        if ($description === '' && $product) {
            $description = $product->name . ' — ' . $billingCycle;
        }

        $rules = [];
        if ($product && RuleBuilder::isSupportedCycle($billingCycle)) {
            $price = RuleBuilder::productPriceCoins($productId, $billingCycle);
            if ($price === null || $price <= 0) {
                $errors[] = 'Для цього товару й циклу не знайдено ціну в UAH (перевірте валюту UAH і ціни продукту).';
            } else {
                $rules = RuleBuilder::normalize(RuleBuilder::ruleForCycle(
                    $billingCycle,
                    $price,
                    RuleBuilder::productSetupFeeCoins($productId, $billingCycle)
                ));
                $errors = array_merge($errors, RuleBuilder::validateForCycle($rules, $billingCycle));
            }
        }
        if ($errors !== []) {
            return self::fail($errors);
        }

        $planKey = PlanKey::make($merchantId, $productId, $billingCycle, null, $category, $rules);

        $lockName = 'alliancepay_plan_' . substr($planKey, 0, 40);
        $locked = (int)(Capsule::selectOne('SELECT GET_LOCK(?, 0) AS l', [$lockName])->l ?? 0) === 1;
        if (!$locked) {
            return self::fail(['Створення цього плану вже виконується. Зачекайте та оновіть сторінку.']);
        }

        try {
            $existing = PlanRepository::findByPlanKey($planKey);
            if ($existing) {
                return self::fail([
                    'Такий план уже існує (#' . $existing['id'] . ', ' . $existing['provider_plan_id'] . ').',
                ]);
            }

            return self::createAtProvider(
                $gatewayParams,
                $merchantId,
                $productId,
                $billingCycle,
                null,
                $planKey,
                $name,
                $description,
                $category,
                $rules,
                $adminId
            );
        } finally {
            Capsule::selectOne('SELECT RELEASE_LOCK(?) AS l', [$lockName]);
        }
    }

    /**
     * @return array{ok: bool, errors: string[]}
     */
    public static function retryInfiniteSchedule(array $gatewayParams, int $planId, ?int $adminId): array
    {
        $plan = PlanRepository::find($planId);
        if (!$plan || $plan['sync_status'] !== SubscriptionConstants::SYNC_ERROR) {
            return ['ok' => false, 'errors' => ['План не потребує повторної синхронізації.']];
        }

        try {
            ApiRunner::run($gatewayParams, static function (AuthorizationDTO $authDto) use ($plan) {
                return (new UpdatePlanVersion())->updatePlanVersion(
                    new PlanVersionUpdateRequestDTO(
                        planId: (string)$plan['provider_plan_id'],
                        versionId: (int)$plan['version_id'],
                        infiniteVersion: true,
                    ),
                    $authDto
                );
            });
        } catch (\Throwable $e) {
            logModuleCall(AlliancePayHelper::GATEWAY_MODULE_NAME, 'Plan Infinite Schedule Retry Error', $plan, $e->getMessage());
            PlanRepository::update($planId, ['sync_error' => $e->getMessage()]);

            return ['ok' => false, 'errors' => [$e->getMessage()]];
        }

        PlanRepository::update($planId, [
            'status' => SubscriptionConstants::PLAN_STATUS_ACTIVE,
            'sync_status' => SubscriptionConstants::SYNC_SYNCED,
            'sync_error' => null,
        ]);
        AuditLog::write(
            SubscriptionConstants::AUDIT_PLAN_CREATED,
            $planId,
            (int)$plan['product_id'],
            $adminId,
            ['note' => 'infinite schedule applied on retry']
        );

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @param array $gatewayParams
     * @param int $planId
     * @param int $coinAmount
     * @param string $appliesFrom
     * @param bool $customersNotified
     * @param string $reason
     * @param int|null $adminId
     * @return array{ok: bool, errors: string[], warning: string|null}
     */
    public static function reprice(
        array $gatewayParams,
        int $planId,
        int $coinAmount,
        string $appliesFrom,
        bool $customersNotified,
        string $reason,
        ?int $adminId
    ): array {
        $plan = PlanRepository::find($planId);
        $errors = [];

        if (!$plan) {
            return ['ok' => false, 'errors' => ['План не знайдено.'], 'warning' => null];
        }
        if ($plan['status'] !== SubscriptionConstants::PLAN_STATUS_ACTIVE
            || $plan['sync_status'] !== SubscriptionConstants::SYNC_SYNCED) {
            $errors[] = 'Змінювати ціну можна лише для активного синхронізованого плану.';
        }
        if (!$customersNotified) {
            $errors[] = 'Підтвердіть, що клієнтів повідомлено (або буде повідомлено) про зміну ціни.';
        }
        if (trim($reason) === '') {
            $errors[] = 'Вкажіть причину зміни.';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $appliesFrom, new \DateTimeZone('Europe/Kyiv'));
        $minDate = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Kyiv')))
            ->modify('+' . SubscriptionConstants::REPRICE_MIN_LEAD_DAYS . ' days');
        if (!$date) {
            $errors[] = 'Невірний формат дати набуття чинності.';
        } elseif ($date < $minDate) {
            $errors[] = 'Нові правила можуть діяти не раніше ніж через '
                . SubscriptionConstants::REPRICE_MIN_LEAD_DAYS . ' днів (з ' . $minDate->format('Y-m-d') . ').';
        }

        $rules = [];
        if ($plan) {
            $setupExtra = count($plan['rules']) === 2
                ? max(0, (int)$plan['rules'][0]['coinAmount'] - (int)$plan['rules'][1]['coinAmount'])
                : 0;
            $rules = RuleBuilder::normalize(RuleBuilder::ruleForCycle((string)$plan['billing_cycle'], $coinAmount, $setupExtra));
            $errors = array_merge($errors, RuleBuilder::validateForCycle($rules, (string)$plan['billing_cycle']));
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'warning' => null];
        }

        try {
            $request = PlanRequestDTO::fromArray([
                'planId' => (string)$plan['provider_plan_id'],
                'name' => (string)$plan['name'],
                'description' => (string)($plan['description'] ?: $plan['name']),
                'categorySubcategoryIndicator' => (string)$plan['category_indicator'],
                'merchantId' => (string)$plan['merchant_id'],
                'appliesDateTimeFrom' => $date->format('Y-m-d H:i:s.') . '000' . $date->format('P'),
                'rules' => $rules,
            ]);

            $result = ApiRunner::run(
                $gatewayParams,
                static fn (AuthorizationDTO $authDto) => (new PlanLifecycleOrchestrator())
                    ->updatePlanWithInfiniteSchedule($request, $authDto)
            );
        } catch (\Throwable $e) {
            logModuleCall(AlliancePayHelper::GATEWAY_MODULE_NAME, 'Plan Reprice Error', ['plan' => $planId, 'rules' => $rules], $e->getMessage());
            AuditLog::write(SubscriptionConstants::AUDIT_PLAN_SYNC_FAILED, $planId, (int)$plan['product_id'], $adminId, [
                'stage' => 'reprice',
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'errors' => ['Банк відхилив зміну плану: ' . $e->getMessage()], 'warning' => null];
        }

        $response = $result->getPlanResponse();
        $ok = $result->isFullySuccessful();
        $newVersionId = (string)$response->getVersionId();
        $appliesFromDb = $date->format('Y-m-d H:i:s');

        VersionHistoryRepository::insert([
            'plan_id' => $planId,
            'old_version_id' => $plan['version_id'],
            'new_version_id' => $newVersionId,
            'old_rules' => $plan['rules'],
            'new_rules' => $rules,
            'applies_from' => $appliesFromDb,
            'reason' => trim($reason),
            'created_by' => $adminId,
        ]);

        $update = [
            'rules' => $rules,
            'version_id' => $newVersionId,
            'applies_from' => $appliesFromDb,
            'sync_status' => $ok ? SubscriptionConstants::SYNC_SYNCED : SubscriptionConstants::SYNC_ERROR,
            'sync_error' => $ok ? null : $result->getInfiniteScheduleError(),
            'status' => $ok ? SubscriptionConstants::PLAN_STATUS_ACTIVE : SubscriptionConstants::PLAN_STATUS_ERROR,
        ];
        $newKey = PlanKey::make(
            (string)$plan['merchant_id'],
            (int)$plan['product_id'],
            (string)$plan['billing_cycle'],
            $plan['template_id'] ?? null,
            (string)$plan['category_indicator'],
            $rules
        );
        if (PlanRepository::findByPlanKey($newKey) === null) {
            $update['plan_key'] = $newKey;
        }
        PlanRepository::update($planId, $update);

        AuditLog::write(SubscriptionConstants::AUDIT_PLAN_REPRICED, $planId, (int)$plan['product_id'], $adminId, [
            'applies_from' => $appliesFromDb,
            'old_version' => $plan['version_id'],
            'new_version' => $newVersionId,
            'affected_subscriptions' => $plan['active_subscriptions_count'],
            'infinite_schedule' => $ok,
        ]);

        if (!$ok) {
            return [
                'ok' => false,
                'errors' => [],
                'warning' => 'Правила оновлено в банку, але крок «безстроковий розклад» не виконано: '
                    . $result->getInfiniteScheduleError() . '. План отримав статус ERROR — повторіть синхронізацію.',
            ];
        }

        return ['ok' => true, 'errors' => [], 'warning' => null];
    }

    public static function setOfferedToNewCustomers(int $planId, bool $offered, ?int $adminId): void
    {
        $plan = PlanRepository::find($planId);
        if (!$plan) {
            return;
        }

        PlanRepository::update($planId, ['is_active' => $offered ? 1 : 0, 'archive_candidate' => 0]);
        AuditLog::write(SubscriptionConstants::AUDIT_PLAN_DEACTIVATED, $planId, (int)$plan['product_id'], $adminId, [
            'offered_to_new_customers' => $offered,
        ]);
    }

    /**
     * @return int
     */
    public static function flagArchiveCandidates(): int
    {
        Capsule::table(SubscriptionSchema::TABLE_PLAN)->update(['archive_candidate' => 0]);

        $ids = array_column(PlanRepository::findArchiveCandidates(), 'id');
        if ($ids !== []) {
            Capsule::table(SubscriptionSchema::TABLE_PLAN)->whereIn('id', $ids)->update(['archive_candidate' => 1]);
        }

        return count($ids);
    }

    /**
     * @return array{ok: bool, errors: string[]}
     */
    public static function archiveAtProvider(array $gatewayParams, int $planId, ?int $adminId): array
    {
        $plan = PlanRepository::find($planId);
        if (!$plan) {
            return ['ok' => false, 'errors' => ['План не знайдено.']];
        }
        if ($plan['is_active'] || $plan['active_subscriptions_count'] > 0) {
            return ['ok' => false, 'errors' => ['Архівувати можна лише неактивний план без активних підписок.']];
        }

        try {
            ApiRunner::run(
                $gatewayParams,
                static fn (AuthorizationDTO $authDto) => (new UpdatePlanStatus())->updatePlanStatus(
                    new PlanStatusUpdateRequestDTO(
                        (string)$plan['provider_plan_id'],
                        (string)$plan['merchant_id'],
                        SubscriptionConstants::PLAN_STATUS_CANCELED
                    ),
                    $authDto
                )
            );
        } catch (\Throwable $e) {
            logModuleCall(AlliancePayHelper::GATEWAY_MODULE_NAME, 'Plan Archive Error', $plan, $e->getMessage());

            return ['ok' => false, 'errors' => [$e->getMessage()]];
        }

        PlanRepository::update($planId, [
            'status' => SubscriptionConstants::PLAN_STATUS_CANCELED,
            'archive_candidate' => 0,
        ]);
        AuditLog::write(SubscriptionConstants::AUDIT_PLAN_ARCHIVED, $planId, (int)$plan['product_id'], $adminId);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @param array $rules
     */
    private static function createAtProvider(
        array $gatewayParams,
        string $merchantId,
        int $productId,
        string $billingCycle,
        ?int $templateId,
        string $planKey,
        string $name,
        string $description,
        string $category,
        array $rules,
        ?int $adminId
    ): array {
        try {
            $request = PlanRequestDTO::fromArray([
                'name' => $name,
                'description' => $description,
                'categorySubcategoryIndicator' => $category,
                'merchantId' => $merchantId,
                'rules' => $rules,
            ]);

            $result = ApiRunner::run(
                $gatewayParams,
                static fn (AuthorizationDTO $authDto) => (new PlanLifecycleOrchestrator())
                    ->createPlanWithInfiniteSchedule($request, $authDto)
            );
        } catch (\Throwable $e) {
            logModuleCall(
                AlliancePayHelper::GATEWAY_MODULE_NAME,
                'Plan Create Error',
                ['name' => $name, 'productId' => $productId, 'rules' => $rules],
                $e->getMessage()
            );
            AuditLog::write(
                SubscriptionConstants::AUDIT_PLAN_SYNC_FAILED,
                null,
                $productId,
                $adminId,
                ['stage' => 'create', 'error' => $e->getMessage()]
            );

            return self::fail(['Банк відхилив створення плану: ' . $e->getMessage()]);
        }

        $response = $result->getPlanResponse();
        $ok = $result->isFullySuccessful();

        $planId = PlanRepository::insert([
            'provider_plan_id' => $response->getPlanId(),
            'product_id' => $productId,
            'billing_cycle' => $billingCycle,
            'template_id' => $templateId,
            'plan_key' => $planKey,
            'is_active' => 1,
            'sync_status' => $ok ? SubscriptionConstants::SYNC_SYNCED : SubscriptionConstants::SYNC_ERROR,
            'sync_error' => $ok ? null : $result->getInfiniteScheduleError(),
            'version_id' => (string)$response->getVersionId(),
            'merchant_id' => $merchantId,
            'name' => $name,
            'description' => $description,
            'status' => $ok ? SubscriptionConstants::PLAN_STATUS_ACTIVE : SubscriptionConstants::PLAN_STATUS_ERROR,
            'category_indicator' => $category,
            'rules' => $rules,
        ]);

        if (!$ok) {
            AuditLog::write(
                SubscriptionConstants::AUDIT_PLAN_SYNC_FAILED,
                $planId,
                $productId,
                $adminId,
                ['stage' => 'infinite_schedule', 'error' => $result->getInfiniteScheduleError()]
            );

            return [
                'ok' => false,
                'plan_id' => $planId,
                'errors' => [],
                'warning' => 'План створено в банку, але крок «безстроковий розклад» не виконано: '
                    . $result->getInfiniteScheduleError()
                    . '. План має статус ERROR — повторіть синхронізацію зі списку планів.',
            ];
        }

        AuditLog::write(
            SubscriptionConstants::AUDIT_PLAN_CREATED,
            $planId,
            $productId,
            $adminId,
            ['provider_plan_id' => $response->getPlanId(), 'plan_key' => $planKey]
        );

        return ['ok' => true, 'plan_id' => $planId, 'errors' => [], 'warning' => null];
    }

    /**
     * @param string[] $errors
     * @return array
     */
    private static function fail(array $errors): array
    {
        return ['ok' => false, 'plan_id' => null, 'errors' => $errors, 'warning' => null];
    }
}
