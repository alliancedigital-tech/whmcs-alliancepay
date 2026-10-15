<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

use WHMCS\Database\Capsule;

/**
 * Class RuleBuilder.
 */
final class RuleBuilder
{
    public const FIELDS = [
        'paymentNumberFrom',
        'paymentNumberTo',
        'intervalValue',
        'intervalUnit',
        'coinAmount',
        'currency',
    ];

    public const INTERVAL_UNITS = ['DAY', 'WEEK', 'MONTH'];

    public const OPEN_ENDED_TO = 999;

    private const CYCLES = [
        'Monthly' => ['monthly', 1],
        'Quarterly' => ['quarterly', 3],
        'Semi-Annually' => ['semiannually', 6],
        'Annually' => ['annually', 12],
        'Biennially' => ['biennially', 24],
        'Triennially' => ['triennially', 36],
    ];

    /**
     * @return string[]
     */
    public static function supportedCycles(): array
    {
        return array_keys(self::CYCLES);
    }

    /**
     * @param string $billingCycle
     * @return bool
     */
    public static function isSupportedCycle(string $billingCycle): bool
    {
        return isset(self::CYCLES[$billingCycle]);
    }

    public static function cycleFromCartKey(string $cartCycle): ?string
    {
        return [
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'semiannually' => 'Semi-Annually',
            'annually' => 'Annually',
            'biennially' => 'Biennially',
            'triennially' => 'Triennially',
        ][strtolower($cartCycle)] ?? null;
    }

    public static function productPriceCoins(int $productId, string $billingCycle): ?int
    {
        if (!self::isSupportedCycle($billingCycle)) {
            return null;
        }

        $currencyId = Capsule::table('tblcurrencies')->where('code', 'UAH')->value('id');
        if (!$currencyId) {
            return null;
        }

        $price = Capsule::table('tblpricing')
            ->where('type', 'product')
            ->where('currency', (int)$currencyId)
            ->where('relid', $productId)
            ->value(self::CYCLES[$billingCycle][0]);

        if ($price === null || (float)$price < 0) {
            return null;
        }

        return (int)round((float)$price * 100);
    }

    public static function productSetupFeeCoins(int $productId, string $billingCycle): int
    {
        $columns = [
            'Monthly' => 'msetupfee',
            'Quarterly' => 'qsetupfee',
            'Semi-Annually' => 'ssetupfee',
            'Annually' => 'asetupfee',
            'Biennially' => 'bsetupfee',
            'Triennially' => 'tsetupfee',
        ];
        $currencyId = Capsule::table('tblcurrencies')->where('code', 'UAH')->value('id');
        if (!isset($columns[$billingCycle]) || !$currencyId) {
            return 0;
        }

        $fee = Capsule::table('tblpricing')
            ->where('type', 'product')
            ->where('currency', (int)$currencyId)
            ->where('relid', $productId)
            ->value($columns[$billingCycle]);

        return ($fee === null || (float)$fee <= 0) ? 0 : (int)round((float)$fee * 100);
    }

    /**
     * @param string $billingCycle
     * @param int $coinAmount
     * @param int $setupFeeCoins
     * @return array[]
     */
    public static function ruleForCycle(string $billingCycle, int $coinAmount, int $setupFeeCoins = 0): array
    {
        $interval = self::CYCLES[$billingCycle][1] ?? 0;
        $rule = static fn (int $from, int $to, int $amount) => [
            'paymentNumberFrom' => $from,
            'paymentNumberTo' => $to,
            'intervalValue' => $interval,
            'intervalUnit' => 'MONTH',
            'coinAmount' => $amount,
            'currency' => SubscriptionConstants::PLAN_CURRENCY,
        ];

        if ($setupFeeCoins > 0) {
            return [$rule(1, 1, $coinAmount + $setupFeeCoins), $rule(2, self::OPEN_ENDED_TO, $coinAmount)];
        }

        return [$rule(1, self::OPEN_ENDED_TO, $coinAmount)];
    }

    /**
     * @param array[] $rules
     * @return string[]
     */
    public static function validateForCycle(array $rules, string $billingCycle): array
    {
        if (!self::isSupportedCycle($billingCycle)) {
            return ['Непідтримуваний billing cycle.'];
        }

        $errors = self::validate($rules);
        if ($errors !== []) {
            return $errors;
        }

        $interval = self::CYCLES[$billingCycle][1];
        $count = count($rules);
        if ($count > 2) {
            $errors[] = 'План підписки може мати одне правило (1..' . self::OPEN_ENDED_TO . ') або два (платіж №1 і 2..' . self::OPEN_ENDED_TO . ').';
        } elseif ($count === 1 && ($rules[0]['paymentNumberFrom'] !== 1 || $rules[0]['paymentNumberTo'] !== self::OPEN_ENDED_TO)) {
            $errors[] = 'Правило має діяти на платежі 1..' . self::OPEN_ENDED_TO . ' (безстрокова підписка).';
        } elseif ($count === 2) {
            [$first, $rest] = $rules;
            if ($first['paymentNumberFrom'] !== 1 || $first['paymentNumberTo'] !== 1
                || $rest['paymentNumberFrom'] !== 2 || $rest['paymentNumberTo'] !== self::OPEN_ENDED_TO) {
                $errors[] = 'Два правила мають покривати платіж №1 і платежі 2..' . self::OPEN_ENDED_TO . '.';
            }
        }

        foreach ($rules as $i => $rule) {
            if ($rule['intervalValue'] !== $interval || $rule['intervalUnit'] !== 'MONTH') {
                $errors[] = 'Правило #' . ($i + 1) . ": інтервал має відповідати циклу {$billingCycle}: {$interval} MONTH.";
            }
            if ($rule['coinAmount'] <= 0) {
                $errors[] = 'Правило #' . ($i + 1) . ': сума платежу має бути більшою за 0.';
            }
        }

        return $errors;
    }

    /**
     * @param array[] $rows
     * @return array[]
     */
    public static function normalize(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $row);
            $filled = array_filter(
                array_intersect_key($row, array_flip(self::FIELDS)),
                static fn ($v) => $v !== '' && $v !== null
            );
            if ($filled === []) {
                continue;
            }

            $normalized = [];
            foreach (self::FIELDS as $field) {
                $value = $row[$field] ?? '';
                $normalized[$field] = match ($field) {
                    'intervalUnit' => $value === '' ? '' : strtoupper((string)$value),
                    'currency' => $value === '' ? '' : (string)$value,
                    default => ($value === '' || !is_numeric($value)) ? $value : (int)$value,
                };
            }
            $result[] = $normalized;
        }

        usort(
            $result,
            static fn (array $a, array $b) => (int)$a['paymentNumberFrom'] <=> (int)$b['paymentNumberFrom']
        );

        return $result;
    }

    /**
     * @param array[] $rules
     * @return string[]
     */
    public static function validate(array $rules): array
    {
        if ($rules === []) {
            return ['Потрібне хоча б одне правило розкладу.'];
        }

        $errors = [];
        $ranges = [];
        $incomplete = false;

        foreach ($rules as $i => $rule) {
            $n = $i + 1;

            foreach (self::FIELDS as $field) {
                if (!isset($rule[$field]) || $rule[$field] === '') {
                    $errors[] = "Правило #$n: поле «{$field}» обов'язкове.";
                }
            }
            if (self::hasMissing($rule)) {
                $incomplete = true;
                continue;
            }

            foreach (['paymentNumberFrom', 'paymentNumberTo', 'intervalValue', 'coinAmount'] as $field) {
                if (!is_int($rule[$field])) {
                    $errors[] = "Правило #$n: «{$field}» має бути цілим числом.";
                }
            }
            if (!in_array($rule['intervalUnit'], self::INTERVAL_UNITS, true)) {
                $errors[] = "Правило #$n: «intervalUnit» має бути одним із " . implode(', ', self::INTERVAL_UNITS) . '.';
            }
            if ((string)$rule['currency'] !== SubscriptionConstants::PLAN_CURRENCY) {
                $errors[] = "Правило #$n: підтримується лише валюта UAH (" . SubscriptionConstants::PLAN_CURRENCY . ').';
            }
            if (is_int($rule['paymentNumberFrom']) && $rule['paymentNumberFrom'] < 1) {
                $errors[] = "Правило #$n: «paymentNumberFrom» має бути ≥ 1.";
            }
            if (is_int($rule['intervalValue']) && $rule['intervalValue'] < 1) {
                $errors[] = "Правило #$n: «intervalValue» має бути ≥ 1.";
            }
            if (is_int($rule['coinAmount']) && $rule['coinAmount'] < 0) {
                $errors[] = "Правило #$n: «coinAmount» не може бути від'ємним.";
            }
            if (is_int($rule['paymentNumberFrom']) && is_int($rule['paymentNumberTo'])) {
                if ($rule['paymentNumberFrom'] > $rule['paymentNumberTo']) {
                    $errors[] = "Правило #$n: «paymentNumberFrom» більше за «paymentNumberTo».";
                } else {
                    $ranges[] = [$rule['paymentNumberFrom'], $rule['paymentNumberTo'], $n];
                }
            }
        }

        usort($ranges, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i][0] <= $ranges[$i - 1][1]) {
                $errors[] = "Правило #{$ranges[$i][2]}: діапазон перетинається з правилом #{$ranges[$i - 1][2]}.";
            }
        }

        if (!$incomplete && $ranges !== [] && $ranges[0][0] !== 1) {
            $errors[] = 'Розклад має починатись з платежу №1.';
        }

        return array_values(array_unique($errors));
    }

    public static function expectedCoinAmount(array $rules, int $paymentNumber): ?int
    {
        foreach ($rules as $rule) {
            if ($paymentNumber >= (int)$rule['paymentNumberFrom'] && $paymentNumber <= (int)$rule['paymentNumberTo']) {
                return (int)$rule['coinAmount'];
            }
        }

        return null;
    }

    /**
     * @param array $rule
     * @return bool
     */
    private static function hasMissing(array $rule): bool
    {
        foreach (self::FIELDS as $field) {
            if (!isset($rule[$field]) || $rule[$field] === '') {
                return true;
            }
        }

        return false;
    }
}
