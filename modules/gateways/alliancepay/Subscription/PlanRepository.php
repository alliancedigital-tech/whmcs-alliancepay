<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

/**
 * Class PlanRepository.
 */
class PlanRepository extends Repository
{
    protected static function table(): string
    {
        return SubscriptionSchema::TABLE_PLAN;
    }

    protected static function casts(): array
    {
        return [
            'id' => 'int',
            'product_id' => 'int',
            'template_id' => 'int',
            'is_active' => 'bool',
            'archive_candidate' => 'bool',
            'active_subscriptions_count' => 'int',
            'rules' => 'json',
        ];
    }

    public static function findByProviderPlanId(string $providerPlanId): ?array
    {
        return self::first(static::query()->where('provider_plan_id', $providerPlanId));
    }

    public static function findByPlanKey(string $planKey): ?array
    {
        return self::first(static::query()->where('plan_key', $planKey));
    }

    /**
     * @return array[]
     */
    public static function findActiveByProduct(int $productId, ?string $billingCycle = null): array
    {
        $query = static::query()
            ->where('product_id', $productId)
            ->where('is_active', 1)
            ->where('status', SubscriptionConstants::PLAN_STATUS_ACTIVE)
            ->where('sync_status', SubscriptionConstants::SYNC_SYNCED);

        if ($billingCycle !== null) {
            $query->where('billing_cycle', $billingCycle);
        }

        return self::all($query->orderBy('id'));
    }

    /**
     * @return array[]
     */
    public static function list(): array
    {
        return self::all(static::query()->orderByDesc('id'));
    }

    /**
     * @return array[]
     */
    public static function findArchiveCandidates(): array
    {
        return self::all(
            static::query()
                ->where('is_active', 0)
                ->where('active_subscriptions_count', 0)
                ->where('status', SubscriptionConstants::PLAN_STATUS_ACTIVE)
        );
    }

    public static function incrementActiveCount(int $planId): void
    {
        static::query()->where('id', $planId)->increment('active_subscriptions_count');
    }

    public static function decrementActiveCount(int $planId): void
    {
        static::query()
            ->where('id', $planId)
            ->where('active_subscriptions_count', '>', 0)
            ->decrement('active_subscriptions_count');
    }
}
