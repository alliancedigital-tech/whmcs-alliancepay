<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Fiscalization;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use WHMCS\Database\Capsule;

/**
 * Class FiscalizationRepository.
 */
class FiscalizationRepository
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAIL = 'FAIL';

    public const STATUS_ERROR = 'ERROR';

    public const TYPE_PURCHASE = 'PURCHASE';

    public const TYPE_REFUND = 'REFUND';

    /**
     * @param string $operationId
     * @return array|null
     */
    public static function findByOperationId(string $operationId): ?array
    {
        return self::first(Capsule::table(FiscalizationSchema::TABLE_FISCALIZATION)->where('operation_id', $operationId));
    }

    /**
     * @param string $merchantRequestId
     * @return array|null
     */
    public static function findByMerchantRequestId(string $merchantRequestId): ?array
    {
        return self::first(
            Capsule::table(FiscalizationSchema::TABLE_FISCALIZATION)->where('merchant_request_id', $merchantRequestId)
        );
    }

    /**
     * @param int $invoiceId
     * @return array[]
     */
    public static function findByInvoice(int $invoiceId): array
    {
        return Capsule::table(FiscalizationSchema::TABLE_FISCALIZATION)
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => (array)$row)
            ->all();
    }

    /**
     * @param string $originalOperationId
     * @return array
     */
    public static function findActiveRefundsOf(string $originalOperationId): array
    {
        return Capsule::table(FiscalizationSchema::TABLE_FISCALIZATION)
            ->where('original_operation_id', $originalOperationId)
            ->where('operation_type', self::TYPE_REFUND)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_SUCCESS])
            ->get()
            ->map(static fn ($row): array => (array)$row)
            ->all();
    }

    /**
     * @param string $operationId
     * @param array $data
     * @return void
     */
    public static function upsert(string $operationId, array $data): void
    {
        $now = self::now();
        $existing = Capsule::table(FiscalizationSchema::TABLE_FISCALIZATION)->where('operation_id', $operationId);

        if ($existing->exists()) {
            $existing->update($data + ['updated_at' => $now]);

            return;
        }

        Capsule::table(FiscalizationSchema::TABLE_FISCALIZATION)->insert(
            $data + ['operation_id' => $operationId, 'created_at' => $now, 'updated_at' => $now]
        );
    }

    /**
     * @param DateTimeImmutable|null $date
     * @return string|null UTC "Y-m-d H:i:s"
     */
    public static function toUtc(?DateTimeImmutable $date): ?string
    {
        return $date === null ? null : $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param string|null $utc
     * @return DateTimeImmutable|null
     * @throws Exception
     */
    public static function fromUtc(?string $utc): ?DateTimeImmutable
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    /**
     * @return string
     * @throws Exception
     */
    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * @param $query
     * @return array|null
     */
    private static function first($query): ?array
    {
        $row = $query->first();

        return $row ? (array)$row : null;
    }
}
