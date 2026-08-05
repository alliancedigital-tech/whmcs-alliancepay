<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

add_hook('ClientAreaPageCart', 1, function ($vars) {
    $hasRecurring = false;
    if (isset($_SESSION['cart']['products'])) {
        foreach ($_SESSION['cart']['products'] as $product) {
            if (isset($product['billingcycle']) && $product['billingcycle'] !== 'onetime') {
                $hasRecurring = true;
                break;
            }
        }
    }

    if (!$hasRecurring && !empty($_SESSION['cart']['domains'])) {
        $hasRecurring = true;
    }

    if (!$hasRecurring && isset($_SESSION['cart']['addons'])) {
        foreach ($_SESSION['cart']['addons'] as $addon) {
            if ($addon['billingcycle'] !== 'onetime') {
                $hasRecurring = true;
                break;
            }
        }
    }

    if ($hasRecurring) {
        $gateways = $vars['gateways'];
        if (isset($gateways['alliancepay'])) {
            unset($gateways['alliancepay']);
        }

        return ['gateways' => $gateways];
    }
});

add_hook('AdminInvoicesControlsOutput', 1, function ($vars) {
    $invoiceId = (int)($vars['invoiceid'] ?? 0);
    if (!$invoiceId) {
        return '';
    }

    require_once __DIR__ . '/../../modules/gateways/alliancepay/vendor/autoload.php';
    require_once __DIR__ . '/../../modules/gateways/alliancepay/AlliancePayHelper.php';

    $record = \AlliancePay\AlliancePayHelper::getPreAuthPendingRecord($invoiceId);
    if (!$record) {
        return '';
    }

    $info       = json_decode($record->additional_information, true) ?? [];
    $hppOrderId = $record->transaction_id;
    $operations = $info['operations'] ?? [];

    $originalOpId   = '';
    $originalAmount = 0;
    foreach ($operations as $op) {
        if (($op['type'] ?? '') === \AlliancePay\AlliancePayHelper::OPERATION_TYPE_PREAUTH
            && ($op['status'] ?? '') === \AlliancePay\AlliancePayHelper::STATUS_SUCCESS
            && !empty($op['operationId'])
        ) {
            $originalOpId   = $op['operationId'];
            $originalAmount = (int)($op['coinAmount'] ?? $info['original_coin_amount'] ?? 0);
            break;
        }
    }

    if (!$originalAmount) {
        $originalAmount = (int)($info['original_coin_amount'] ?? 0);
    }

    if (!$hppOrderId) {
        return '';
    }

    $adminStatusUrl = '../modules/gateways/alliancepay/admin_status.php'
        . '?hppOrderId=' . urlencode($hppOrderId);

    $opInfo = $originalOpId
        ? ' | Operation: <code>' . htmlspecialchars($originalOpId) . '</code>'
        : '';
    $amountInfo = $originalAmount
        ? ' | Сума: <strong>' . number_format($originalAmount / 100, 2) . '</strong>'
        : '';

    $html  = '<div class="alert alert-warning" style="margin-top:15px;padding:15px;border-radius:4px;">';
    $html .= '<strong>AlliancePay PREAUTH</strong> — кошти зарезервовано, ще не списано.';
    $html .= $opInfo . $amountInfo . '<br><br>';
    $html .= '<a href="' . htmlspecialchars($adminStatusUrl) . '" target="_blank" '
        . 'class="btn btn-success btn-sm">'
        . 'Підтвердити списання (Completion)'
        . '</a>';
    $html .= '</div>';

    return $html;
});

add_hook('ClientAreaPageViewInvoice', 1, function ($vars) {

    $invoiceId = isset($vars['invoiceid'])
        ? (int) $vars['invoiceid']
        : (isset($_GET['id']) ? (int) $_GET['id'] : 0);

    if (!$invoiceId) {
        return [];
    }

    $hasRecurringItems = Capsule::table('tblinvoiceitems as ii')
        ->where('ii.invoiceid', $invoiceId)
        ->where(function ($query) {
            $query->orWhere(function ($subquery) {
                $subquery->where('ii.type', 'Hosting')
                    ->whereExists(function ($subSubquery) {
                        $subSubquery->select(Capsule::raw(1))
                            ->from('tblhosting as h')
                            ->whereRaw('h.id = ii.relid')
                            ->where('h.billingcycle', '!=', 'One Time');
                    });
            });

            $query->orWhere(function ($subquery) {
                $subquery->where('ii.type', 'Addon')
                    ->whereExists(function ($subSubquery) {
                        $subSubquery->select(Capsule::raw(1))
                            ->from('tblhostingaddons as ha')
                            ->whereRaw('ha.id = ii.relid')
                            ->where('ha.billingcycle', '!=', 'One Time');
                    });
            });

            $query->orWhere(function ($subquery) {
                $subquery->whereIn('ii.type', ['Domain', 'DomainRegister', 'DomainTransfer']);
            });
        })->exists();

    if (!$hasRecurringItems) {
        return [];
    }

    if (!empty($vars['availableGateways']) && is_array($vars['availableGateways'])) {
        if (isset($vars['availableGateways']['alliancepay'])) {
            unset($vars['availableGateways']['alliancepay']);
        }
    }

    return $vars;
});
