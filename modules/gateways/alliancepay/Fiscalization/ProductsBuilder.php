<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Fiscalization;

use RuntimeException;
use WHMCS\Database\Capsule;

/**
 * Class ProductsBuilder.
 */
class ProductsBuilder
{
    public function __construct(private FiscalProductResolver $resolver)
    {
    }

    /**
     * @param int $invoiceId
     * @param int $operationCoins
     * @return array
     */
    public function buildPurchase(int $invoiceId, int $operationCoins): array
    {
        if ($operationCoins <= 0) {
            throw new RuntimeException('Сума операції має бути більшою за нуль.');
        }

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            throw new RuntimeException("Інвойс #{$invoiceId} не знайдено.");
        }

        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->orderBy('id')->get();

        $lines = [];
        $taxedBase = 0;
        foreach ($items as $item) {
            $kop = (int)round(((float)$item->amount) * 100);
            if ($kop <= 0) {
                continue;
            }
            $lines[] = ['item' => $item, 'base' => $kop];
            if ((int)$item->taxed === 1) {
                $taxedBase += $kop;
            }
        }

        if ($lines === []) {
            throw new RuntimeException("Інвойс #{$invoiceId} не містить позицій із додатною сумою.");
        }

        $tax = (int)round(((float)$invoice->tax + (float)$invoice->tax2) * 100);
        $weights = [];
        foreach ($lines as $i => $line) {
            $w = (float)$line['base'];
            if ($taxedBase > 0 && $tax > 0 && (int)$line['item']->taxed === 1) {
                $w += $line['base'] * $tax / $taxedBase;
            }
            $weights[$i] = $w;
        }

        $allocated = self::allocate($operationCoins, $weights);

        $products = [];
        foreach ($lines as $i => $line) {
            $item = $line['item'];
            if ($allocated[$i] <= 0) {
                throw new RuntimeException(
                    'Позиція «' . $item->description . '» після розподілу має ціну ≤ 0. Фіскалізація неможлива.'
                );
            }

            $productId = $this->productIdOfItem($item);
            $fiscal = $this->resolver->resolve($productId);

            $products[] = $this->product(
                $productId !== null ? 'p' . $productId : 'i' . $item->id,
                (string)$item->description,
                1.0,
                $allocated[$i] / 100,
                $fiscal
            );
        }

        return $products;
    }

    /**
     * @param array $originalProducts
     * @param int $refundCoins
     * @return array
     */
    public function buildRefund(array $originalProducts, int $refundCoins): array
    {
        if ($refundCoins <= 0) {
            throw new RuntimeException('Сума повернення має бути більшою за нуль.');
        }

        $originalCoins = 0;
        foreach ($originalProducts as $p) {
            $originalCoins += self::lineCoins((float)$p['count'], (float)$p['unitPrice']);
        }

        if ($refundCoins > $originalCoins) {
            throw new RuntimeException('Сума повернення перевищує суму фіскалізованої покупки.');
        }

        if ($refundCoins === $originalCoins) {
            return array_values($originalProducts);
        }

        $ratio = $refundCoins / $originalCoins;
        $result = [];
        foreach ($originalProducts as $p) {
            $count = round((float)$p['count'] * $ratio, 3);
            if ($count > 0) {
                $p['count'] = $count;
                $result[] = $p;
            }
        }

        if ($result === []) {
            throw new RuntimeException('Сума повернення надто мала для пропорційного розподілу по позиціях.');
        }

        $sumOthers = 0;
        $last = count($result) - 1;
        foreach ($result as $i => $p) {
            if ($i !== $last) {
                $sumOthers += self::lineCoins((float)$p['count'], (float)$p['unitPrice']);
            }
        }

        $unitCoins = (float)$result[$last]['unitPrice'] * 100;
        $remaining = $refundCoins - $sumOthers;
        $lastCount = $unitCoins > 0 ? round($remaining / $unitCoins, 3) : 0.0;
        if ($lastCount <= 0) {
            throw new RuntimeException('Не вдалося пропорційно розподілити суму повернення по позиціях.');
        }
        $result[$last]['count'] = $lastCount;

        $total = 0;
        foreach ($result as $p) {
            $total += self::lineCoins((float)$p['count'], (float)$p['unitPrice']);
        }
        if ($total !== $refundCoins) {
            throw new RuntimeException(sprintf(
                'Суму повернення %.2f грн не вдалося розподілити по позиціях без зміни ціни (отримано %.2f грн). '
                . 'Зверніться до підтримки або виконайте повернення повною сумою.',
                $refundCoins / 100,
                $total / 100
            ));
        }

        return $result;
    }

    /**
     * @param array[] $products
     * @return int
     */
    public static function totalCoins(array $products): int
    {
        $total = 0;
        foreach ($products as $p) {
            $total += self::lineCoins((float)$p['count'], (float)$p['unitPrice']);
        }

        return $total;
    }

    /**
     * @param int $total
     * @param array $weights
     * @return array
     */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $result = [];
        $given = 0;
        $lastKey = array_key_last($weights);

        foreach ($weights as $key => $w) {
            if ($key === $lastKey) {
                $result[$key] = $total - $given;
                break;
            }
            $part = (int)floor($total * $w / $sum);
            $result[$key] = $part;
            $given += $part;
        }

        return $result;
    }

    /**
     * @param float $count
     * @param float $unitPrice
     * @return int
     */
    private static function lineCoins(float $count, float $unitPrice): int
    {
        return (int)round($count * $unitPrice * 100);
    }

    /**
     * @param object $item
     * @return int|null
     */
    private function productIdOfItem(object $item): ?int
    {
        if ($item->type === 'Hosting' && (int)$item->relid > 0) {
            $pid = Capsule::table('tblhosting')->where('id', (int)$item->relid)->value('packageid');

            return $pid ? (int)$pid : null;
        }

        return null;
    }

    /**
     * @param string $id
     * @param string $name
     * @param float $count
     * @param float $unitPrice
     * @param array $fiscal
     * @return array
     */
    private function product(string $id, string $name, float $count, float $unitPrice, array $fiscal): array
    {
        $product = [
            'id' => $id,
            'name' => trim($name) !== '' ? trim($name) : $id,
            'count' => $count,
            'measurementCode' => $fiscal['measurementCode'],
            'unitPrice' => round($unitPrice, 2),
        ];

        if ($fiscal['taxCode'] !== null) {
            $product['taxCode'] = $fiscal['taxCode'];
        }
        if ($fiscal['classificationCode'] !== null) {
            $product['classificationCode'] = $fiscal['classificationCode'];
        }
        if ($fiscal['exciseLabels'] !== []) {
            $product['exciseLabels'] = $fiscal['exciseLabels'];
        }

        return $product;
    }
}
