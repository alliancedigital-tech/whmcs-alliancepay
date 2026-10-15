<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

/**
 * Class SubscriptionRepository.
 */
class SubscriptionRepository extends Repository
{
    protected static function table(): string
    {
        return SubscriptionSchema::TABLE_SUBSCRIPTION;
    }

    protected static function casts(): array
    {
        return [
            'id' => 'int',
            'service_id' => 'int',
            'invoice_id' => 'int',
            'client_id' => 'int',
            'plan_id' => 'int',
            'rules_snapshot' => 'json',
            'callback_data' => 'json',
        ];
    }

    public static function findByProviderOrderId(string $hppOrderId): ?array
    {
        return self::first(static::query()->where('provider_order_id', $hppOrderId));
    }

    public static function findByMerchantRequestId(string $merchantRequestId): ?array
    {
        return self::first(static::query()->where('merchant_request_id', $merchantRequestId));
    }

    /**
     * @return array[]
     */
    public static function findByService(int $serviceId): array
    {
        return self::all(static::query()->where('service_id', $serviceId)->orderByDesc('id'));
    }

    /**
     * @return array[]
     */
    public static function list(int $limit = 200): array
    {
        return self::all(static::query()->orderByDesc('id')->limit($limit));
    }
}
