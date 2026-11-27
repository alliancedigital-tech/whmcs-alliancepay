<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Fiscalization;

/**
 * Class FiscalProductResolver.
 */
class FiscalProductResolver
{
    /** @var array<int, array> */
    private array $productCache = [];

    public function __construct(private FiscalizationSettings $settings)
    {
    }

    /**
     * @param int|null $productId
     * @return array{taxCode: ?int, classificationCode: ?string, exciseLabels: string[], measurementCode: string}
     */
    public function resolve(?int $productId): array
    {
        $defaults = $this->settings->defaults();
        $product = $productId !== null ? $this->productValues($productId) : [];

        $pick = static function (string $field) use ($product, $defaults): string {
            $own = trim((string)($product[$field] ?? ''));

            return $own !== '' ? $own : (string)($defaults[$field] ?? '');
        };

        $tax = $pick('taxCode');
        $cls = $pick('classificationCode');

        return [
            'taxCode' => ($tax !== '' && ctype_digit($tax)) ? (int)$tax : null,
            'classificationCode' => ($cls !== '' && ctype_digit($cls)) ? $cls : null,
            'exciseLabels' => FiscalizationSettings::parseExciseLabels($pick('exciseLabels')),
            'measurementCode' => $pick('measurementCode') ?: FiscalizationSettings::DEFAULT_MEASUREMENT_CODE,
        ];
    }

    /**
     * @param int $productId
     * @return array
     */
    private function productValues(int $productId): array
    {
        return $this->productCache[$productId] ??= ProductFiscalRepository::get($productId);
    }
}
