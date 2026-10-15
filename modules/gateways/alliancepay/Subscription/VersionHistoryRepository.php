<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

/**
 * Class VersionHistoryRepository.
 */
class VersionHistoryRepository extends Repository
{
    protected static function table(): string
    {
        return SubscriptionSchema::TABLE_VERSION_HISTORY;
    }

    protected static function casts(): array
    {
        return ['id' => 'int', 'plan_id' => 'int', 'created_by' => 'int', 'old_rules' => 'json', 'new_rules' => 'json'];
    }

    protected static function hasUpdatedAt(): bool
    {
        return false;
    }

    /**
     * @return array[]
     */
    public static function listByPlan(int $planId): array
    {
        return self::all(static::query()->where('plan_id', $planId)->orderByDesc('id'));
    }
}
