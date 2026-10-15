<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

use AlliancePay\AlliancePayHelper;
use AlliancePay\Sdk\Payment\Dto\Subscription\Callback\ScheduledPaymentDTO;
use AlliancePay\Sdk\Payment\Dto\Subscription\Callback\SubscriptionCallbackDTO;
use WHMCS\Database\Capsule;

/**
 * Class CallbackProcessor.
 */
class CallbackProcessor
{
    private const FAIL_STATUSES = ['FAIL', 'FAILED', 'REJECTED', 'ERROR', 'DECLINED'];

    private const PENDING_STATUSES = ['PENDING', 'PROCESSING', 'IN_PROGRESS'];

    private const WAITING_STATUSES = ['WAITING', 'SCHEDULED', 'NEW'];

    public function __construct(private string $gatewayName, private string $gatewayModule = AlliancePayHelper::GATEWAY_MODULE_NAME)
    {
    }

    /**
     * @param SubscriptionCallbackDTO $callback
     * @return void
     */
    public function process(SubscriptionCallbackDTO $callback): void
    {
        $hppOrderId = $callback->getHppOrderId();
        $payload = $callback->getRawPayload();
        $operation = $callback->getOperation();

        $sub = SubscriptionRepository::findByProviderOrderId($hppOrderId);

        logTransaction($this->gatewayName, [
            'hppOrderId' => $hppOrderId,
            'orderStatus' => $callback->getOrderStatus(),
            'recurringTransactionType' => $callback->getRecurringTransactionType(),
            'isInitial' => $this->isInitial($callback),
            'operationRecurringType' => $this->operationPayload($callback)['recurringTransactionType'] ?? null,
            'scheduledPaymentId' => $this->scheduledPaymentId($callback),
            'operation' => $operation === null ? null : [
                'type' => $operation->getType(),
                'status' => $operation->getStatus(),
                'operationId' => $operation->getOperationId(),
                'coinAmount' => $operation->getCoinAmount(),
            ],
            'localSubscription' => $sub === null ? null : [
                'id' => $sub['id'],
                'status' => $sub['status'],
                'service_id' => $sub['service_id'],
                'invoice_id' => $sub['invoice_id'],
            ],
        ], 'Subscription callback received');

        if (!$sub) {
            logTransaction($this->gatewayName, $payload, 'Subscription callback for unknown hppOrderId ' . $hppOrderId);
            return;
        }

        $this->rememberCallback($sub, $callback);
        $this->syncSchedule($sub, $callback);

        if ($operation !== null && $operation->getType() === AlliancePayHelper::OPERATION_TYPE_REFUND) {
            logTransaction($this->gatewayName, $payload, 'Subscription refund callback. HPP Order: ' . $hppOrderId);
            return;
        }

        if ($this->isInitial($callback)) {
            $this->processInitial($sub, $callback);
        } else {
            $this->processRecurring($sub, $callback);
        }

        $this->logOutcome($sub, $callback);
    }

    /**
     * @param array $sub
     * @param SubscriptionCallbackDTO $callback
     * @return void
     */
    private function logOutcome(array $sub, SubscriptionCallbackDTO $callback): void
    {
        $operationId = $callback->getOperation()?->getOperationId();
        $fresh = SubscriptionRepository::find((int)$sub['id']);
        $invoiceId = (int)$sub['invoice_id'];

        logTransaction($this->gatewayName, [
            'subscriptionStatus' => $fresh['status'] ?? null,
            'firstInvoiceId' => $invoiceId,
            'firstInvoiceStatus' => Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status'),
            'unpaidInvoiceOfService' => InvoiceMatcher::findUnpaidInvoiceForService((int)$sub['service_id']),
            'operationId' => $operationId,
            'operationRecordedInLedger' => $operationId ? InvoiceMatcher::isTransactionRecorded($this->gatewayModule, $operationId) : null,
            'scheduledPayment' => $operationId ? ScheduledPaymentRepository::findByOperationId($operationId) : null,
        ], 'Subscription callback outcome');
    }

    /**
     * @param array $sub
     * @param SubscriptionCallbackDTO $callback
     * @return void
     */
    private function processInitial(array $sub, SubscriptionCallbackDTO $callback): void
    {
        $status = $this->effectiveStatus($callback);
        $operation = $callback->getOperation();

        if ($this->isPaid($callback)) {
            $coins = $operation->getCoinAmount();
            $operationId = $operation->getOperationId();

            $row = $this->resolveScheduledRow($sub, $callback, 1);
            ScheduledPaymentRepository::updateKeepingExisting($row['id'], [
                'operation_id' => $this->claimOperationId($operationId, $row['id']),
                'coin_amount' => $coins,
                'payment_number' => $row['payment_number'] ?? 1,
            ]);

            $this->applyPayment($sub, $row, $callback, (int)$sub['invoice_id'], $coins, $operationId);
            $this->activate($sub);

            return;
        }

        if (in_array($status, self::FAIL_STATUSES, true)) {
            if ($sub['status'] === SubscriptionConstants::SUB_STATUS_PENDING) {
                SubscriptionRepository::update($sub['id'], ['status' => SubscriptionConstants::SUB_STATUS_FAILED]);
            }
            logTransaction($this->gatewayName, $callback->getRawPayload(), "Subscription initial payment failed ({$status}). HPP Order: " . $sub['provider_order_id']);
            return;
        }

        logTransaction($this->gatewayName, $callback->getRawPayload(), "Subscription initial payment status: {$status}. HPP Order: " . $sub['provider_order_id']);
    }

    /**
     * @param array $sub
     * @param SubscriptionCallbackDTO $callback
     * @return void
     */
    private function processRecurring(array $sub, SubscriptionCallbackDTO $callback): void
    {
        $scheduleEntry = $this->scheduleEntry($callback, $this->scheduledPaymentId($callback));
        $paymentNumber = $scheduleEntry?->getPaymentNumber();

        $row = $this->resolveScheduledRow($sub, $callback, $paymentNumber);

        if ($this->isPaid($callback)) {
            $operation = $callback->getOperation();
            $coins = $operation->getCoinAmount();
            $operationId = $operation->getOperationId();

            $mismatch = false;
            $number = $row['payment_number'] ?? $paymentNumber;
            if ($number !== null) {
                $warning = $this->reconcile($sub, $callback, (int)$number);
                if ($warning !== null) {
                    $mismatch = true;
                    logActivity('AlliancePay subscription: ' . $warning);
                }
            }

            ScheduledPaymentRepository::updateKeepingExisting($row['id'], [
                'operation_id' => $this->claimOperationId($operationId, $row['id']),
                'coin_amount' => $coins,
                'payment_number' => $number,
            ]);
            if ($mismatch) {
                ScheduledPaymentRepository::update($row['id'], ['amount_mismatch' => 1]);
            }

            $invoiceId = InvoiceMatcher::findUnpaidInvoiceForService((int)$sub['service_id'])
                ?? InvoiceMatcher::tryGenerateInvoice((int)$sub['service_id'], (int)$sub['client_id']);

            $this->applyPayment($sub, $row, $callback, $invoiceId, $coins, $operationId);

            $this->activate($sub);
            return;
        }

        $this->applyNonSuccessStatus($row, $callback, $scheduleEntry);
    }

    /**
     * @return string|null
     */
    private function reconcile(array $sub, SubscriptionCallbackDTO $callback, int $paymentNumber): ?string
    {
        $candidates = [$sub['rules_snapshot']];
        $plan = PlanRepository::find((int)$sub['plan_id']);
        if ($plan) {
            $candidates[] = $plan['rules'];
        }

        $warning = null;
        foreach ($candidates as $rules) {
            $expected = is_array($rules) ? RuleBuilder::expectedCoinAmount($rules, $paymentNumber) : null;
            if ($expected === null) {
                continue;
            }
            $warning = $callback->reconcileAmount($expected);
            if ($warning === null) {
                return null;
            }
        }

        return $warning;
    }

    /**
     * @param array $row
     * @param SubscriptionCallbackDTO $callback
     * @param ScheduledPaymentDTO|null $entry
     * @return void
     */
    private function applyNonSuccessStatus(array $row, SubscriptionCallbackDTO $callback, ?ScheduledPaymentDTO $entry): void
    {
        $raw = strtoupper((string)($entry?->getStatus() ?? $this->effectiveStatus($callback)));
        $status = $this->mapStatus($raw);

        if ($status === null) {
            logTransaction($this->gatewayName, $callback->getRawPayload(), "Subscription callback with unknown status '{$raw}' — recorded for review");
            ScheduledPaymentRepository::update($row['id'], ['status' => SubscriptionConstants::PAYMENT_NEEDS_REVIEW]);
            return;
        }

        if ($row['status'] === SubscriptionConstants::PAYMENT_SUCCESS) {
            return;
        }

        $data = ['status' => $status];
        if ($status === SubscriptionConstants::PAYMENT_RETRY || $status === SubscriptionConstants::PAYMENT_FAIL) {
            $data['retry_count'] = (int)$row['retry_count'] + ($status === SubscriptionConstants::PAYMENT_RETRY ? 1 : 0);
        }
        ScheduledPaymentRepository::update($row['id'], $data);

        logTransaction($this->gatewayName, $callback->getRawPayload(), "Subscription scheduled payment {$status}. HPP Order: " . $callback->getHppOrderId());
    }

    /**
     * @param string $status
     * @return string|null
     */
    private function mapStatus(string $status): ?string
    {
        return match (true) {
            $status === 'SUCCESS' => SubscriptionConstants::PAYMENT_SUCCESS,
            in_array($status, self::FAIL_STATUSES, true) => SubscriptionConstants::PAYMENT_FAIL,
            $status === 'RETRY' => SubscriptionConstants::PAYMENT_RETRY,
            $status === 'SKIPPED_PAUSED' => SubscriptionConstants::PAYMENT_SKIPPED_PAUSED,
            $status === 'INVALIDATED' => SubscriptionConstants::PAYMENT_INVALIDATED,
            in_array($status, self::PENDING_STATUSES, true) => SubscriptionConstants::PAYMENT_PROCESSING,
            in_array($status, self::WAITING_STATUSES, true) => SubscriptionConstants::PAYMENT_WAITING,
            default => null,
        };
    }

    /**
     * @param SubscriptionCallbackDTO $callback
     * @return array
     */
    private function operationPayload(SubscriptionCallbackDTO $callback): array
    {
        $raw = $callback->getRawPayload();
        if (isset($raw['operation']) && is_array($raw['operation'])) {
            return $raw['operation'];
        }

        $operationId = $callback->getOperation()?->getOperationId();
        foreach ((array)($raw['operations'] ?? []) as $operation) {
            if (is_array($operation) && ($operation['operationId'] ?? null) === $operationId) {
                return $operation;
            }
        }

        return [];
    }

    /**
     * @param SubscriptionCallbackDTO $callback
     * @return bool
     */
    private function isInitial(SubscriptionCallbackDTO $callback): bool
    {
        $operation = $this->operationPayload($callback);
        if (array_key_exists('isInitial', $operation)) {
            return (bool)$operation['isInitial'];
        }

        return $callback->isInitialPayment();
    }

    /**
     * @param SubscriptionCallbackDTO $callback
     * @return string|null
     */
    private function scheduledPaymentId(SubscriptionCallbackDTO $callback): ?string
    {
        $id = $callback->getScheduledPaymentId() ?? ($this->operationPayload($callback)['scheduledPaymentId'] ?? null);

        return $id === null || $id === '' ? null : (string)$id;
    }

    /**
     * @param SubscriptionCallbackDTO $callback
     * @return string
     */
    private function effectiveStatus(SubscriptionCallbackDTO $callback): string
    {
        $operation = $callback->getOperation();

        return strtoupper($operation !== null ? $operation->getStatus() : $callback->getOrderStatus());
    }

    private function isPaid(SubscriptionCallbackDTO $callback): bool
    {
        $operation = $callback->getOperation();

        return $operation !== null
            && strtoupper($operation->getStatus()) === 'SUCCESS'
            && $operation->getOperationId() !== '';
    }

    /**
     * @param array $sub
     * @param array $row
     * @param SubscriptionCallbackDTO $callback
     * @param int|null $invoiceId
     * @param int $coins
     * @param string $operationId
     * @return void
     */
    private function applyPayment(
        array $sub,
        array $row,
        SubscriptionCallbackDTO $callback,
        ?int $invoiceId,
        int $coins,
        string $operationId
    ): void {
        if (InvoiceMatcher::isTransactionRecorded($this->gatewayModule, $operationId)) {
            ScheduledPaymentRepository::updateKeepingExisting($row['id'], [
                'status' => SubscriptionConstants::PAYMENT_SUCCESS,
                'executed_at' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        if (!$invoiceId || !Capsule::table('tblinvoices')->where('id', $invoiceId)->exists()) {
            ScheduledPaymentRepository::update($row['id'], ['status' => SubscriptionConstants::PAYMENT_NEEDS_REVIEW]);
            logTransaction(
                $this->gatewayName,
                $callback->getRawPayload(),
                "Subscription payment {$operationId} received but no invoice found for service #{$sub['service_id']} — needs review"
            );
            logActivity("AlliancePay subscription: payment {$operationId} ({$coins} коп.) has no matching invoice (service #{$sub['service_id']}).");
            return;
        }

        addInvoicePayment($invoiceId, $operationId, $coins / 100, 0, $this->gatewayModule);

        ScheduledPaymentRepository::update($row['id'], [
            'status' => SubscriptionConstants::PAYMENT_SUCCESS,
            'executed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->appendCheckLink($operationId, $invoiceId, $sub['provider_order_id'] ?? $callback->getHppOrderId());

        logTransaction(
            $this->gatewayName,
            $callback->getRawPayload(),
            'Subscription payment successful. HPP Order: ' . $callback->getHppOrderId() . ", Operation: {$operationId}, Invoice: {$invoiceId}"
        );
    }

    /**
     * @param string $operationId
     * @param int $invoiceId
     * @param string $hppOrderId
     * @return void
     */
    private function appendCheckLink(string $operationId, int $invoiceId, string $hppOrderId): void
    {
        $transaction = Capsule::table('tblaccounts')
            ->where('transid', $operationId)
            ->where('invoiceid', $invoiceId)
            ->orderBy('id', 'desc')
            ->first();

        if ($transaction) {
            Capsule::table('tblaccounts')->where('id', $transaction->id)->update([
                'description' => $transaction->description . ' | HPP Order ID: ' . $hppOrderId,
            ]);
        }
    }

    /**
     * @param array $sub
     * @return void
     */
    private function activate(array $sub): void
    {
        $transitioned = Capsule::table(SubscriptionSchema::TABLE_SUBSCRIPTION)
            ->where('id', $sub['id'])
            ->whereIn('status', [SubscriptionConstants::SUB_STATUS_PENDING, SubscriptionConstants::SUB_STATUS_FAILED])
            ->update(['status' => SubscriptionConstants::SUB_STATUS_ACTIVE, 'updated_at' => date('Y-m-d H:i:s')]);

        if ($transitioned > 0) {
            PlanRepository::incrementActiveCount((int)$sub['plan_id']);
        }

        if ($sub['status'] !== SubscriptionConstants::SUB_STATUS_CANCELED) {
            Capsule::table('tblhosting')
                ->where('id', $sub['service_id'])
                ->update(['subscriptionid' => $sub['provider_order_id']]);
        }
    }

    /**
     * @param array $sub
     * @param SubscriptionCallbackDTO $callback
     * @return void
     */
    private function rememberCallback(array $sub, SubscriptionCallbackDTO $callback): void
    {
        $payload = $callback->getRawPayload();
        $hasOperation = $callback->getOperation() !== null;

        if ($hasOperation || empty($sub['callback_data'])) {
            SubscriptionRepository::update($sub['id'], ['callback_data' => $payload]);
        }
    }

    /**
     * @param array $sub
     * @param SubscriptionCallbackDTO $callback
     * @return void
     */
    private function syncSchedule(array $sub, SubscriptionCallbackDTO $callback): void
    {
        foreach ($callback->getScheduledPayments() as $entry) {
            $spId = $entry->getScheduledPaymentId();
            if ($spId === null) {
                continue;
            }

            $existing = ScheduledPaymentRepository::findByScheduledPaymentId((int)$sub['id'], $spId);
            if (!$existing) {
                ScheduledPaymentRepository::insert([
                    'subscription_id' => (int)$sub['id'],
                    'scheduled_payment_id' => $spId,
                    'payment_number' => $entry->getPaymentNumber(),
                    'status' => $this->mapStatus(strtoupper((string)$entry->getStatus())) ?? SubscriptionConstants::PAYMENT_WAITING,
                    'scheduled_date' => $entry->getScheduledDateTime(),
                    'coin_amount' => $entry->getCoinAmount(),
                ]);
                continue;
            }

            ScheduledPaymentRepository::updateKeepingExisting($existing['id'], [
                'payment_number' => $entry->getPaymentNumber(),
                'scheduled_date' => $entry->getScheduledDateTime(),
                'coin_amount' => $entry->getCoinAmount(),
            ]);
        }
    }

    /**
     * @param SubscriptionCallbackDTO $callback
     * @param string|null $scheduledPaymentId
     * @return ScheduledPaymentDTO|null
     */
    private function scheduleEntry(SubscriptionCallbackDTO $callback, ?string $scheduledPaymentId): ?ScheduledPaymentDTO
    {
        if ($scheduledPaymentId === null) {
            return null;
        }
        foreach ($callback->getScheduledPayments() as $entry) {
            if ($entry->getScheduledPaymentId() === $scheduledPaymentId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array $sub
     * @param SubscriptionCallbackDTO $callback
     * @param int|null $paymentNumber
     * @return array
     */
    private function resolveScheduledRow(array $sub, SubscriptionCallbackDTO $callback, ?int $paymentNumber): array
    {
        $operationId = $callback->getOperation()?->getOperationId();
        if ($operationId) {
            $byOperation = ScheduledPaymentRepository::findByOperationId($operationId);
            if ($byOperation) {
                return $byOperation;
            }
        }

        $spId = $this->scheduledPaymentId($callback);
        if ($spId !== null) {
            $row = ScheduledPaymentRepository::findByScheduledPaymentId((int)$sub['id'], $spId);
            if ($row) {
                return $row;
            }
        }

        if ($this->isInitial($callback)) {
            foreach (ScheduledPaymentRepository::listBySubscription((int)$sub['id']) as $row) {
                if ($row['payment_number'] === 1 && $row['operation_id'] === null) {
                    return $row;
                }
            }
        }

        $id = ScheduledPaymentRepository::insert([
            'subscription_id' => (int)$sub['id'],
            'scheduled_payment_id' => $spId,
            'payment_number' => $paymentNumber,
            'status' => SubscriptionConstants::PAYMENT_PROCESSING,
        ]);

        return ScheduledPaymentRepository::find($id);
    }

    /**
     * @param string $operationId
     * @param int $rowId
     * @return string|null
     */
    private function claimOperationId(string $operationId, int $rowId): ?string
    {
        $owner = ScheduledPaymentRepository::findByOperationId($operationId);

        return ($owner === null || $owner['id'] === $rowId) ? $operationId : null;
    }
}
