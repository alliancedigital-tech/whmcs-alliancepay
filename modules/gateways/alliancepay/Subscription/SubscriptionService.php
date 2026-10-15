<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

use AlliancePay\AlliancePayHelper;
use AlliancePay\Sdk\Payment\Dto\Subscription\SubscriptionOrder\SubscriptionOrderCancelRequestDTO;
use AlliancePay\Sdk\Payment\Dto\Subscription\SubscriptionOrder\SubscriptionOrderRequestDTO;
use AlliancePay\Sdk\Payment\Subscription\SubscriptionOrder\CancelSubscriptionOrder;
use AlliancePay\Sdk\Payment\Subscription\SubscriptionOrder\CreateSubscriptionOrder;
use AlliancePay\Sdk\Services\Authorization\Dto\AuthorizationDTO;
use AlliancePay\Sdk\Services\RequestIdentification\GenerateRequestIdentification;
use WHMCS\Database\Capsule;

/**
 * Class SubscriptionService.
 */
class SubscriptionService
{
    public const POST_ACTION = 'create_subscription';

    /**
     * @param array $params
     * @return bool
     */
    public static function isEnabled(array $params): bool
    {
        $target = strtolower(SubscriptionConstants::CONFIG_ENABLE_SUBSCRIPTIONS);
        foreach ($params as $key => $value) {
            if (strtolower((string)$key) === $target) {
                return self::isTruthy(is_scalar($value) ? (string)$value : '');
            }
        }

        return false;
    }

    public static function isEnabledGlobally(): bool
    {
        return self::isTruthy(self::rawEnableSetting()['value']);
    }

    /**
     * @return array{key: string|null, value: string|null, keys: string[], source: string}
     */
    public static function rawEnableSetting(): array
    {
        $target = strtolower(SubscriptionConstants::CONFIG_ENABLE_SUBSCRIPTIONS);
        $found = ['key' => null, 'value' => null, 'keys' => [], 'source' => 'getGatewayVariables'];

        if (!function_exists('getGatewayVariables') && defined('ROOTDIR')) {
            require_once ROOTDIR . '/includes/gatewayfunctions.php';
        }

        $settings = [];
        if (function_exists('getGatewayVariables')) {
            $settings = (array)getGatewayVariables(AlliancePayHelper::GATEWAY_MODULE_NAME);
        }

        if ($settings === []) {
            $found['source'] = 'tblpaymentgateways';
            foreach (Capsule::table('tblpaymentgateways')->where('gateway', AlliancePayHelper::GATEWAY_MODULE_NAME)->get(['setting', 'value']) as $row) {
                $settings[(string)$row->setting] = (string)$row->value;
            }
        }

        foreach ($settings as $key => $value) {
            $found['keys'][] = (string)$key;
            if (strtolower((string)$key) === $target) {
                $found['key'] = (string)$key;
                $found['value'] = is_scalar($value) ? (string)$value : '';
            }
        }

        if ($found['value'] !== null && preg_match('/^[0-9a-f]{64,}$/i', $found['value']) && function_exists('decrypt')) {
            try {
                $decrypted = (string)decrypt($found['value']);
                if ($decrypted !== '') {
                    $found['value'] = $decrypted;
                    $found['source'] .= '+decrypt';
                }
            } catch (\Throwable $e) {
            }
        }

        return $found;
    }

    /**
     * @param string|null $value
     * @return bool
     */
    private static function isTruthy(?string $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['on', '1', 'yes', 'true'], true);
    }

    /**
     * @param array $cart
     * @return bool
     */
    public static function isCartSubscribable(array $cart): bool
    {
        return self::explainCart($cart)[0];
    }

    /**
     * @param array $cart
     * @return array{0: bool, 1: string}
     */
    public static function explainCart(array $cart): array
    {
        if (!self::isEnabledGlobally()) {
            $raw = self::rawEnableSetting();

            return [false, 'Enable Subscriptions вимкнено або не знайдено в tblpaymentgateways. Знайдений ключ: '
                . var_export($raw['key'], true) . ', значення: ' . var_export($raw['value'], true)
                . ', джерело: ' . $raw['source'] . '. Усі ключі шлюзу: ' . implode(', ', $raw['keys'])];
        }
        if (!empty($cart['domains'])) {
            return [false, 'У кошику є домени'];
        }
        if (!empty($cart['addons'])) {
            return [false, 'У кошику є addons'];
        }

        $products = $cart['products'] ?? [];
        if (count($products) !== 1) {
            return [false, 'У кошику ' . count($products) . ' продуктів, потрібен рівно 1'];
        }

        $product = reset($products);
        $pid = (int)($product['pid'] ?? 0);
        $rawCycle = (string)($product['billingcycle'] ?? '');
        $cycle = RuleBuilder::cycleFromCartKey($rawCycle);
        if ($cycle === null) {
            return [false, "Цикл у кошику «{$rawCycle}» не рекурентний/непідтримуваний (pid {$pid})"];
        }
        if ((int)($product['qty'] ?? 1) !== 1) {
            return [false, 'Кількість продукту ' . (int)$product['qty'] . ', потрібна 1'];
        }

        if (PlanRepository::findActiveByProduct($pid, $cycle) !== []) {
            return [true, "Є активний план для pid {$pid}, цикл {$cycle}"];
        }

        $all = array_map(
            static fn (array $p) => "#{$p['id']} {$p['billing_cycle']} status={$p['status']} sync={$p['sync_status']} offered=" . (int)$p['is_active'],
            array_filter(PlanRepository::list(), static fn (array $p) => $p['product_id'] === $pid)
        );

        return [false, "Немає активного плану для pid {$pid}, цикл {$cycle}. Плани продукту: " . ($all ? implode('; ', $all) : 'немає')];
    }

    public static function isInvoiceSubscribable(int $invoiceId): bool
    {
        if (!self::isEnabledGlobally()) {
            return false;
        }

        $service = InvoiceMatcher::singleServiceForInvoice($invoiceId);

        return $service !== null
            && PlanRepository::findActiveByProduct($service['product_id'], $service['billing_cycle']) !== [];
    }

    /**
     * @param array $params
     * @return array|null
     */
    public static function findEligiblePlan(array $params): ?array
    {
        if (!self::isEnabled($params)) {
            return null;
        }

        [$plan, $reason] = self::evaluate($params);

        if ($plan === null && $reason !== null) {
            logModuleCall(
                AlliancePayHelper::GATEWAY_MODULE_NAME,
                'Subscription not offered',
                ['invoiceid' => $params['invoiceid'] ?? null, 'amount' => $params['amount'] ?? null, 'currency' => $params['currency'] ?? null],
                $reason
            );
        }

        return $plan;
    }

    /**
     * @param array $params
     * @return array{0: array|null, 1: string|null}
     */
    private static function evaluate(array $params): array
    {
        $currency = strtoupper((string)($params['currency'] ?? ''));
        if ($currency !== 'UAH') {
            return [null, "Валюта оплати {$currency}, потрібна UAH (перевірте Convert To For Processing)."];
        }

        $invoiceId = (int)($params['invoiceid'] ?? 0);
        if (InvoiceMatcher::isInvoicePaid($invoiceId)) {
            return [null, null];
        }

        $service = InvoiceMatcher::singleServiceForInvoice($invoiceId);
        if (!$service) {
            return [null, 'У рахунку немає рівно одного сервісу (Hosting) або є позиції інших типів (домен, addon тощо).'];
        }
        if (!RuleBuilder::isSupportedCycle($service['billing_cycle'])) {
            return [null, "Цикл сервісу «{$service['billing_cycle']}» не підтримується для підписок."];
        }

        $plans = PlanRepository::findActiveByProduct($service['product_id'], $service['billing_cycle']);
        if ($plans === []) {
            $any = PlanRepository::findActiveByProduct($service['product_id']);

            return [null, $any === []
                ? null
                : "Для товару #{$service['product_id']} немає активного плану з циклом «{$service['billing_cycle']}»."
                    . ' Цикли наявних планів: ' . implode(', ', array_unique(array_column($any, 'billing_cycle'))) . '.'];
        }

        $invoiceCoins = (int)round((float)$params['amount'] * 100);
        $expected = [];
        foreach ($plans as $plan) {
            $first = RuleBuilder::expectedCoinAmount($plan['rules'], 1);
            if ($first === $invoiceCoins) {
                return [$plan + ['_service' => $service], null];
            }
            $expected[] = "план #{$plan['id']}: " . ($first ?? 'н/д');
        }

        return [null, "Сума рахунку {$invoiceCoins} коп. не збігається з платежем №1 плану (" . implode('; ', $expected)
            . '). Перевірте податки, промокоди, setup fee та суму в правилах.'];
    }

    /**
     * @param array $params
     * @param array $plan
     * @return string
     * @throws \Throwable
     */
    public static function createOrder(array $params, array $plan): string
    {
        $service = $plan['_service'];
        $merchantRequestId = GenerateRequestIdentification::generateRequestId();

        $notificationUrl = rtrim((string)$params['systemurl'], '/') . '/modules/gateways/callback/alliancepay_subscription.php';

        $request = SubscriptionOrderRequestDTO::fromArray([
            'planId' => (string)$plan['provider_plan_id'],
            'merchantId' => (string)$params['merchantId'],
            'merchantRequestId' => $merchantRequestId,
            'purpose' => 'Subscription, invoice #' . $params['invoiceid'],
            'notificationUrl' => $notificationUrl,
            'statusPageType' => $params['statusPageType'] ?? AlliancePayHelper::STATUS_PAGE_TYPE_DEFAULT,
            'successUrl' => $params['returnurl'],
            'failUrl' => $params['returnurl'],
            'hppPaymentMethods' => AlliancePayHelper::HPP_PAYMENT_METHODS,
            'directType' => AlliancePayHelper::DIRECT_TYPE_REDIRECT,
            'customerData' => AlliancePayHelper::buildCustomerData($params['clientdetails']),
        ]);

        $response = ApiRunner::run(
            $params,
            static fn (AuthorizationDTO $authDto) => (new CreateSubscriptionOrder())->createSubscriptionOrder($request, $authDto)
        );

        $redirectUrl = $response->getRedirectUrl();
        if (!str_starts_with($redirectUrl, 'https://')) {
            throw new \UnexpectedValueException('Invalid redirect URL received from payment gateway.');
        }

        SubscriptionRepository::insert([
            'service_id' => $service['service_id'],
            'invoice_id' => (int)$params['invoiceid'],
            'client_id' => $service['client_id'],
            'plan_id' => (int)$plan['id'],
            'rules_snapshot' => $plan['rules'],
            'provider_order_id' => $response->getHppOrderId(),
            'merchant_request_id' => $merchantRequestId,
            'status' => SubscriptionConstants::SUB_STATUS_PENDING,
        ]);

        logTransaction(
            $params['name'],
            [
                'InvoiceID' => $params['invoiceid'],
                'RequestID' => $merchantRequestId,
                'HppOrderId' => $response->getHppOrderId(),
                'PlanID' => $plan['provider_plan_id'],
            ],
            'Subscription Order Created'
        );

        return $redirectUrl;
    }

    /**
     * @param array $params
     * @param string $hppOrderId
     * @return void
     * @throws \Throwable
     */
    public static function cancel(array $params, string $hppOrderId): void
    {
        $merchantId = (string)$params['merchantId'];

        ApiRunner::run(
            $params,
            static fn (AuthorizationDTO $authDto) => (new CancelSubscriptionOrder())->cancelSubscriptionOrder(
                new SubscriptionOrderCancelRequestDTO($hppOrderId, $merchantId),
                $authDto
            )
        );

        self::markCanceled($hppOrderId);
    }

    public static function markCanceled(string $hppOrderId): void
    {
        $sub = SubscriptionRepository::findByProviderOrderId($hppOrderId);
        if (!$sub || $sub['status'] === SubscriptionConstants::SUB_STATUS_CANCELED) {
            return;
        }

        $wasActive = $sub['status'] === SubscriptionConstants::SUB_STATUS_ACTIVE;
        SubscriptionRepository::update($sub['id'], ['status' => SubscriptionConstants::SUB_STATUS_CANCELED]);
        if ($wasActive) {
            PlanRepository::decrementActiveCount($sub['plan_id']);
        }

        Capsule::table('tblhosting')
            ->where('id', $sub['service_id'])
            ->where('subscriptionid', $hppOrderId)
            ->update(['subscriptionid' => '']);
    }
}
