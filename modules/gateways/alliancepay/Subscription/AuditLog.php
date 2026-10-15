<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

/**
 * Class AuditLog.
 */
class AuditLog extends Repository
{
    protected static function table(): string
    {
        return SubscriptionSchema::TABLE_AUDIT_LOG;
    }

    protected static function casts(): array
    {
        return ['id' => 'int', 'plan_id' => 'int', 'product_id' => 'int', 'user_id' => 'int', 'details' => 'json'];
    }

    protected static function hasUpdatedAt(): bool
    {
        return false;
    }

    public static function write(
        string $action,
        ?int $planId = null,
        ?int $productId = null,
        ?int $userId = null,
        array $details = []
    ): void {
        self::insert([
            'action' => $action,
            'plan_id' => $planId,
            'product_id' => $productId,
            'user_id' => $userId,
            'details' => $details,
        ]);
    }

    /**
     * @return array[]
     */
    public static function recent(int $limit = 100): array
    {
        return self::all(static::query()->orderByDesc('id')->limit($limit));
    }
}
