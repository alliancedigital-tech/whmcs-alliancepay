<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

/**
 * Class TemplateRepository.
 */
class TemplateRepository extends Repository
{
    protected static function table(): string
    {
        return SubscriptionSchema::TABLE_TEMPLATE;
    }

    protected static function casts(): array
    {
        return ['id' => 'int', 'rules_template' => 'json'];
    }

    public static function findByCode(string $code): ?array
    {
        return self::first(static::query()->where('code', $code));
    }

    /**
     * @param bool $onlyActive
     * @return array[]
     */
    public static function list(bool $onlyActive = false): array
    {
        $query = static::query()->orderBy('name');
        if ($onlyActive) {
            $query->where('status', SubscriptionConstants::TEMPLATE_ACTIVE);
        }

        return self::all($query);
    }

    public static function isUsed(int $templateId): bool
    {
        return \WHMCS\Database\Capsule::table(SubscriptionSchema::TABLE_PLAN)
            ->where('template_id', $templateId)
            ->exists();
    }
}
