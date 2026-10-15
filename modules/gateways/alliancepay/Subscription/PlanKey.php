<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

/**
 * Class PlanKey.
 */
final class PlanKey
{
    /**
     * @param string $merchantId
     * @param int $productId
     * @param string $billingCycle
     * @param int|null $templateId
     * @param string $categoryIndicator
     * @param array[] $rules
     * @return string
     */
    public static function make(
        string $merchantId,
        int $productId,
        string $billingCycle,
        ?int $templateId,
        string $categoryIndicator,
        array $rules
    ): string {
        return hash('sha256', json_encode([
            'merchantId' => $merchantId,
            'productId' => $productId,
            'billingCycle' => $billingCycle,
            'templateId' => $templateId ?? 0,
            'category' => $categoryIndicator,
            'rules' => self::normalizeRules($rules),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array[] $rules
     * @return array[]
     */
    public static function normalizeRules(array $rules): array
    {
        $normalized = array_map(static fn (array $r) => [
            'paymentNumberFrom' => (int)$r['paymentNumberFrom'],
            'paymentNumberTo' => (int)$r['paymentNumberTo'],
            'intervalValue' => (int)$r['intervalValue'],
            'intervalUnit' => strtoupper((string)$r['intervalUnit']),
            'coinAmount' => (int)$r['coinAmount'],
            'currency' => (string)$r['currency'],
        ], $rules);

        usort($normalized, static fn (array $a, array $b) => $a['paymentNumberFrom'] <=> $b['paymentNumberFrom']);

        return $normalized;
    }
}
