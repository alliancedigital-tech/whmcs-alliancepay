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
            // Hosting services
            $query->orWhere(function ($subquery) {
                $subquery->where('ii.type', 'Hosting')
                    ->whereExists(function ($subSubquery) {
                        $subSubquery->select(Capsule::raw(1))
                            ->from('tblhosting as h')
                            ->whereRaw('h.id = ii.relid')
                            ->where('h.billingcycle', '!=', 'One Time');
                    });
            });

            // Hosting Addons
            $query->orWhere(function ($subquery) {
                $subquery->where('ii.type', 'Addon')
                    ->whereExists(function ($subSubquery) {
                        $subSubquery->select(Capsule::raw(1))
                            ->from('tblhostingaddons as ha')
                            ->whereRaw('ha.id = ii.relid')
                            ->where('ha.billingcycle', '!=', 'One Time');
                    });
            });

            // Domain Registrations (always recurring - annual renewal)
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
