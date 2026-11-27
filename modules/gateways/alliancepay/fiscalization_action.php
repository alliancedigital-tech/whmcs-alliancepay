<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 *
 * Admin actions: "Фіскалізувати" / "Оновити статус". POST only, admin session + CSRF token required.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/AlliancePayHelper.php';
require_once __DIR__ . '/Fiscalization/autoload.php';

use AlliancePay\AlliancePayHelper;
use AlliancePay\Fiscalization\AdminBlock;
use AlliancePay\Fiscalization\FiscalizationService;
use WHMCS\Authentication\CurrentUser;

$currentUser = new CurrentUser();
if (!$currentUser->isAuthenticatedAdmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Доступ заборонено.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit('Method not allowed.');
}

if (function_exists('check_token')) {
    check_token('WHMCS.admin.default');
}

$admin = $currentUser->admin();
if ($admin && method_exists($admin, 'hasPermission') && !$admin->hasPermission('Manage Invoice')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Недостатньо прав.');
}

$invoiceId = (int)($_POST['invoiceid'] ?? 0);
$operationId = trim((string)($_POST['operation_id'] ?? ''));
$action = (string)($_POST['action'] ?? '');

$message = 'Невідома дія.';
$gatewayParams = getGatewayVariables(AlliancePayHelper::GATEWAY_MODULE_NAME);

if ($invoiceId > 0 && $operationId !== '' && in_array($action, ['fiscalize', 'refresh'], true) && !empty($gatewayParams['type'])) {
    // Never trust amounts / types from the request: take them from the invoice transactions.
    $operation = null;
    foreach (AdminBlock::operations($invoiceId) as $op) {
        if ($op['operation_id'] === $operationId) {
            $operation = $op;
            break;
        }
    }

    $service = new FiscalizationService($gatewayParams);
    if ($operation === null) {
        $message = 'Операцію не знайдено в транзакціях інвойсу.';
    } elseif ($action === 'refresh') {
        $message = $service->refresh($operationId)['message'];
    } else {
        $message = $service->fiscalize(
            $invoiceId,
            $operationId,
            $operation['type'],
            $operation['coins'],
            $operation['original_operation_id']
        )['message'];
    }
}

$adminFolder = \App::get_admin_folder_name();
$_SESSION['alliancepay_fiscal_flash'] = $message;
header('Location: ../../../' . $adminFolder . '/invoices.php?action=edit&id=' . $invoiceId);
exit;
