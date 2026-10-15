<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

/**
 * Class ScheduledPaymentRepository.
 */
class ScheduledPaymentRepository extends Repository
{
    protected static function table(): string
    {
        return SubscriptionSchema::TABLE_SCHEDULED_PAYMENT;
    }

    protected static function casts(): array
    {
        return [
            'id' => 'int',
            'subscription_id' => 'int',
            'payment_number' => 'int',
            'coin_amount' => 'int',
            'amount_mismatch' => 'bool',
            'retry_count' => 'int',
        ];
    }

    public static function findByScheduledPaymentId(int $subscriptionId, string $scheduledPaymentId): ?array
    {
        return self::first(
            static::query()
                ->where('subscription_id', $subscriptionId)
                ->where('scheduled_payment_id', $scheduledPaymentId)
        );
    }

    public static function findByOperationId(string $operationId): ?array
    {
        return self::first(static::query()->where('operation_id', $operationId));
    }

    /**
     * @return array[]
     */
    public static function listBySubscription(int $subscriptionId): array
    {
        return self::all(
            static::query()->where('subscription_id', $subscriptionId)->orderBy('payment_number')->orderBy('id')
        );
    }

    public static function updateKeepingExisting(int $id, array $data): void
    {
        $data = array_filter($data, static fn ($value) => $value !== null);
        if ($data !== []) {
            self::update($id, $data);
        }
    }
}
