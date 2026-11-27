<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Fiscalization;

use Exception;
use WHMCS\Database\Capsule;

/**
 * Class ProductFiscalRepository.
 */
class ProductFiscalRepository
{
    /**
     * @param int $productId
     * @return array{taxCode: ?string, classificationCode: ?string, exciseLabels: ?string, measurementCode: ?string}
     */
    public static function get(int $productId): array
    {
        $row = Capsule::table(FiscalizationSchema::TABLE_PRODUCT_FISCAL)->where('product_id', $productId)->first();

        return [
            'taxCode' => $row->tax_code ?? null,
            'classificationCode' => $row->classification_code ?? null,
            'exciseLabels' => $row->excise_labels ?? null,
            'measurementCode' => $row->measurement_code ?? null,
        ];
    }

    /**
     * @param int $productId
     * @param array $values
     * @return void
     * @throws Exception
     */
    public static function save(int $productId, array $values): void
    {
        $nullable = static fn ($v): ?string => ($v === null || trim((string)$v) === '') ? null : trim((string)$v);

        Capsule::table(FiscalizationSchema::TABLE_PRODUCT_FISCAL)->updateOrInsert(
            ['product_id' => $productId],
            [
                'tax_code' => $nullable($values['taxCode'] ?? null),
                'classification_code' => $nullable($values['classificationCode'] ?? null),
                'excise_labels' => $nullable($values['exciseLabels'] ?? null),
                'measurement_code' => $nullable($values['measurementCode'] ?? null),
                'updated_at' => FiscalizationRepository::now(),
            ]
        );
    }
}
