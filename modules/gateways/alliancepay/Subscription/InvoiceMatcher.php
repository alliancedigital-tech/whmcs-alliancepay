<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

use WHMCS\Database\Capsule;

/**
 * Class InvoiceMatcher.
 */
class InvoiceMatcher
{
    /**
     * @return array{service_id: int, product_id: int, billing_cycle: string, client_id: int}
     */
    public static function singleServiceForInvoice(int $invoiceId): ?array
    {
        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get(['type', 'relid']);

        $hostingIds = [];
        foreach ($items as $item) {
            if ($item->type === 'Hosting') {
                $hostingIds[] = (int)$item->relid;
            } elseif (!in_array($item->type, ['Setup', 'PromoHosting', 'GroupDiscount', 'LateFee'], true)) {
                return null;
            }
        }

        if (count($hostingIds) !== 1) {
            return null;
        }

        $service = Capsule::table('tblhosting')->where('id', $hostingIds[0])->first(['id', 'packageid', 'billingcycle', 'userid']);
        if (!$service) {
            return null;
        }

        return [
            'service_id' => (int)$service->id,
            'product_id' => (int)$service->packageid,
            'billing_cycle' => (string)$service->billingcycle,
            'client_id' => (int)$service->userid,
        ];
    }

    public static function findUnpaidInvoiceForService(int $serviceId): ?int
    {
        $id = Capsule::table('tblinvoiceitems')
            ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
            ->where('tblinvoiceitems.type', 'Hosting')
            ->where('tblinvoiceitems.relid', $serviceId)
            ->where('tblinvoices.status', 'Unpaid')
            ->orderBy('tblinvoices.duedate')
            ->orderBy('tblinvoices.id')
            ->value('tblinvoices.id');

        return $id ? (int)$id : null;
    }

    public static function tryGenerateInvoice(int $serviceId, int $clientId): ?int
    {
        try {
            require_once ROOTDIR . '/includes/processinvoices.php';
            createInvoices($clientId, true, true, ['products' => [$serviceId]]);
        } catch (\Throwable $e) {
            logActivity('AlliancePay subscriptions: invoice generation failed for service #' . $serviceId . ': ' . $e->getMessage());
        }

        return self::findUnpaidInvoiceForService($serviceId);
    }

    public static function isTransactionRecorded(string $gateway, string $transactionId): bool
    {
        return Capsule::table('tblaccounts')
            ->where('gateway', $gateway)
            ->where('transid', $transactionId)
            ->exists();
    }

    public static function isInvoicePaid(int $invoiceId): bool
    {
        return Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status') === 'Paid';
    }
}
