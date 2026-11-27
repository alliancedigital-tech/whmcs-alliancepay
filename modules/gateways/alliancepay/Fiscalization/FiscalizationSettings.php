<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Fiscalization;

/**
 * Class FiscalizationSettings.
 */
class FiscalizationSettings
{
    public const KEY_ENABLED = 'fiscalEnabled';

    public const KEY_AUTO = 'fiscalAuto';

    public const KEY_TAX_CODE = 'fiscalTaxCode';

    public const KEY_CLASSIFICATION_CODE = 'fiscalClassificationCode';

    public const KEY_EXCISE_LABELS = 'fiscalExciseLabels';

    public const KEY_MEASUREMENT_CODE = 'fiscalMeasurementCode';

    public const DEFAULT_MEASUREMENT_CODE = '0001';

    public const CLASSIFICATION_REQUIRED_TAX_CODES = [2, 7, 8];

    public function __construct(private array $gatewayParams)
    {
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return self::isOn($this->gatewayParams[self::KEY_ENABLED] ?? '');
    }

    /**
     * @return bool
     */
    public function isAuto(): bool
    {
        return $this->isEnabled() && self::isOn($this->gatewayParams[self::KEY_AUTO] ?? '');
    }

    /**
     * @return array{taxCode: string, classificationCode: string, exciseLabels: string, measurementCode: string}
     */
    public function defaults(): array
    {
        $measurement = trim((string)($this->gatewayParams[self::KEY_MEASUREMENT_CODE] ?? ''));

        return [
            'taxCode' => trim((string)($this->gatewayParams[self::KEY_TAX_CODE] ?? '')),
            'classificationCode' => trim((string)($this->gatewayParams[self::KEY_CLASSIFICATION_CODE] ?? '')),
            'exciseLabels' => trim((string)($this->gatewayParams[self::KEY_EXCISE_LABELS] ?? '')),
            'measurementCode' => $measurement !== '' ? $measurement : self::DEFAULT_MEASUREMENT_CODE,
        ];
    }

    /**
     * @return string[]
     */
    public function validateDefaults(): array
    {
        $errors = self::validateValues($this->defaults(), 'Налаштування шлюзу');
        $d = $this->defaults();

        if (
            $d['taxCode'] !== ''
            && ctype_digit($d['taxCode'])
            && in_array((int)$d['taxCode'], self::CLASSIFICATION_REQUIRED_TAX_CODES, true)
            && $d['classificationCode'] === ''
        ) {
            $errors[] = 'Налаштування шлюзу: УКТЗЕД обов\'язковий, якщо код податкової групи 2, 7 або 8.';
        }

        return $errors;
    }

    /**
     * @param array $values
     * @param string $context
     * @return array
     */
    public static function validateValues(array $values, string $context): array
    {
        $errors = [];
        $tax = trim((string)($values['taxCode'] ?? ''));
        $cls = trim((string)($values['classificationCode'] ?? ''));
        $measure = trim((string)($values['measurementCode'] ?? ''));

        if ($tax !== '' && !ctype_digit($tax)) {
            $errors[] = $context . ': код податкової групи має бути цілим числом.';
        }

        if ($cls !== '' && !ctype_digit($cls)) {
            $errors[] = $context . ': УКТЗЕД має містити лише цифри.';
        }

        if ($measure !== '' && mb_strlen($measure) > 16) {
            $errors[] = $context . ': код одиниці виміру задовгий.';
        }

        return $errors;
    }

    /**
     * @param string|null $raw
     * @return array
     */
    public static function parseExciseLabels(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $result = [];
        foreach (preg_split('/[,\r\n]+/', $raw) ?: [] as $label) {
            $label = trim($label);
            if ($label !== '') {
                $result[$label] = $label;
            }
        }

        return array_values($result);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function isOn($value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['on', '1', 'yes', 'true'], true);
    }
}
