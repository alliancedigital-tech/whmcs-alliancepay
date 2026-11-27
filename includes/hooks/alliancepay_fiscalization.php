<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 *
 * AlliancePay fiscalization: invoice block (admin) and fiscal attributes in the product form.
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../modules/gateways/alliancepay/AlliancePayHelper.php';
require_once __DIR__ . '/../../modules/gateways/alliancepay/Fiscalization/autoload.php';

use AlliancePay\Fiscalization\AdminBlock;
use AlliancePay\Fiscalization\FiscalizationSchema;
use AlliancePay\Fiscalization\FiscalizationSettings;

/**
 * @return array
 */
function alliancepay_fiscal_gateway_params(): array
{
    try {
        $params = getGatewayVariables('alliancepay');

        return !empty($params['type']) ? $params : [];
    } catch (\Throwable $e) {
        return [];
    }
}

add_hook('AdminInvoicesControlsOutput', 1, function ($vars) {
    try {
        $params = alliancepay_fiscal_gateway_params();
        if ($params === []) {
            return '';
        }

        FiscalizationSchema::ensure();

        $flash = (string)($_SESSION['alliancepay_fiscal_flash'] ?? '');
        unset($_SESSION['alliancepay_fiscal_flash']);

        return AdminBlock::renderInvoiceBlock((int)($vars['invoiceid'] ?? 0), $params, generate_token('plain'), $flash);
    } catch (\Throwable $e) {
        logModuleCall('alliancepay', 'Fiscalization hook: invoice block', [], $e->getMessage());

        return '';
    }
});

add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    try {
        if (($vars['filename'] ?? '') !== 'configproducts' || ($_GET['action'] ?? '') !== 'edit') {
            return '';
        }

        $params = alliancepay_fiscal_gateway_params();
        if ($params === [] || !(new FiscalizationSettings($params))->isEnabled()) {
            return '';
        }

        FiscalizationSchema::ensure();

        return AdminBlock::renderProductForm((int)($_GET['id'] ?? 0));
    } catch (\Throwable $e) {
        logModuleCall('alliancepay', 'Fiscalization hook: product form', [], $e->getMessage());

        return '';
    }
});

add_hook('ProductEdit', 1, function ($vars) {
    try {
        $productId = (int)($vars['pid'] ?? 0);
        if ($productId <= 0 || alliancepay_fiscal_gateway_params() === []) {
            return;
        }

        FiscalizationSchema::ensure();

        $errors = AdminBlock::saveProductFromPost($productId, $_POST);
        if ($errors !== []) {
            logActivity('AlliancePay fiscalization: product #' . $productId . ' not saved: ' . implode(' ', $errors));
        }
    } catch (\Throwable $e) {
        logModuleCall('alliancepay', 'Fiscalization hook: product save', [], $e->getMessage());
    }
});
