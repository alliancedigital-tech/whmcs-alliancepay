<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

use AlliancePay\Sdk\Payment\Dto\Subscription\Payment\RecurringPaymentTriggerRequestDTO;
use AlliancePay\Sdk\Payment\Dto\Subscription\Plan\GetPlanRequestDTO;
use AlliancePay\Sdk\Payment\Order\CheckOrderData;
use AlliancePay\Sdk\Payment\Subscription\Payment\TriggerRecurringPayment;
use AlliancePay\Sdk\Payment\Subscription\Plan\GetPlan;
use AlliancePay\Sdk\Services\Authorization\Dto\AuthorizationDTO;
use AlliancePay\Sdk\Services\RequestIdentification\GenerateRequestIdentification;

/**
 * Class SubscriptionDiagnostics.
 */
class SubscriptionDiagnostics
{
    /**
     * @param array $gatewayParams
     * @param string $hppOrderId
     * @return array{order: array|null, operations: array[], error: string|null}
     */
    public static function fetchOrder(array $gatewayParams, string $hppOrderId): array
    {
        try {
            $order = ApiRunner::run(
                $gatewayParams,
                static fn (AuthorizationDTO $authDto) => (new CheckOrderData())->checkOrderData($hppOrderId, $authDto)
            );

            $operations = array_map(static fn ($op) => $op->toArray(), $order->getOperations());

            return [
                'order' => ['status' => $order->getOrderStatus(), 'coinAmount' => $order->getCoinAmount()],
                'operations' => $operations,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            logModuleCall('alliancepay', 'Subscription Diagnostics Error', $hppOrderId, $e->getMessage());

            return ['order' => null, 'operations' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array $gatewayParams
     * @param string $providerPlanId
     * @return array{details: array|null, error: string|null}
     */
    public static function fetchPlan(array $gatewayParams, string $providerPlanId): array
    {
        try {
            $details = ApiRunner::run(
                $gatewayParams,
                static fn (AuthorizationDTO $authDto) => (new GetPlan())->getPlan(new GetPlanRequestDTO($providerPlanId), $authDto)
            );

            return ['details' => $details->toArray(), 'error' => null];
        } catch (\Throwable $e) {
            logModuleCall('alliancepay', 'Plan Details Error', $providerPlanId, $e->getMessage());

            return ['details' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array $gatewayParams
     * @param int $scheduledRowId
     * @param int|null $adminId
     * @return array{ok: bool, message: string}
     */
    public static function runNow(array $gatewayParams, int $scheduledRowId, ?int $adminId): array
    {
        $row = ScheduledPaymentRepository::find($scheduledRowId);
        $sub = $row ? SubscriptionRepository::find($row['subscription_id']) : null;

        if (!$row || !$sub) {
            return ['ok' => false, 'message' => 'Запланований платіж не знайдено.'];
        }
        if ($sub['status'] !== SubscriptionConstants::SUB_STATUS_ACTIVE) {
            return ['ok' => false, 'message' => 'Підписка не активна.'];
        }
        if (empty($row['scheduled_payment_id'])) {
            return ['ok' => false, 'message' => 'У запису немає scheduledPaymentId (його немає в колбеках банку).'];
        }
        if ($row['status'] === SubscriptionConstants::PAYMENT_SUCCESS) {
            return ['ok' => false, 'message' => 'Цей платіж уже успішно проведено.'];
        }

        $date = $row['scheduled_date']
            ?: (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Kyiv')))->format('Y-m-d H:i:s.vP');

        try {
            $response = ApiRunner::run(
                $gatewayParams,
                static fn (AuthorizationDTO $authDto) => (new TriggerRecurringPayment())->triggerRecurringPayment(
                    new RecurringPaymentTriggerRequestDTO(
                        GenerateRequestIdentification::generateRequestId(),
                        (string)$gatewayParams['merchantId'],
                        (string)$date,
                        (string)$row['scheduled_payment_id'],
                        'Manual trigger from WHMCS admin'
                    ),
                    $authDto
                )
            );
        } catch (\Throwable $e) {
            logModuleCall('alliancepay', 'Run Now Error', $row, $e->getMessage());
            AuditLog::write(SubscriptionConstants::AUDIT_RUN_NOW, null, null, $adminId, [
                'scheduled_row' => $scheduledRowId,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        ScheduledPaymentRepository::update($scheduledRowId, [
            'status' => SubscriptionConstants::PAYMENT_PROCESSING,
            'retry_count' => (int)$row['retry_count'] + 1,
        ]);
        AuditLog::write(SubscriptionConstants::AUDIT_RUN_NOW, (int)$sub['plan_id'], null, $adminId, [
            'scheduled_row' => $scheduledRowId,
            'operation_id' => $response->getOperationId(),
            'status' => $response->getStatus(),
        ]);

        return [
            'ok' => true,
            'message' => 'Платіж ініційовано (operation ' . $response->getOperationId() . ', статус ' . $response->getStatus()
                . '). Результат прийде колбеком.',
        ];
    }
}
