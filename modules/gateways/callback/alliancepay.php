<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../alliancepay/vendor/autoload.php';
require_once __DIR__ . '/../alliancepay/AlliancePayHelper.php';

use alliancepay\AlliancePayHelper;
use AlliancePay\Sdk\Payment\Callback\CallbackHandler;
use WHMCS\Database\Capsule;

$gatewayModuleName = 'alliancepay';
$gatewayParams = getGatewayVariables($gatewayModuleName);
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$bodyJson = file_get_contents('php://input');
$payload = json_decode($bodyJson, true);

if (!$payload) {
    http_response_code(400);
    exit('Invalid JSON');
}

$invoiceId = $_GET['invoiceid'] ?? null;

if (!$invoiceId || !is_numeric($invoiceId)) {
    logTransaction($gatewayParams['name'], $_GET, "Callback Error: Missing or invalid 'invoiceid' in URL");
    http_response_code(200);
    exit('OK');
}

try {
    $authDto = AlliancePayHelper::getAuthDto($gatewayParams);
    $callbackHandler = new CallbackHandler();

    $callback = $callbackHandler->handle($authDto, $payload);

    $callbackData = $callback->toArray();
    $orderStatus = $callbackData['orderStatus'];
    $operationStatus = $callbackData['operation']->getStatus();
    $hppOrderId = $callbackData['hppOrderId'];
    $merchantRequestId = $callbackData['merchantRequestId'];
    $operationId = $callbackData['operation']->getOperationId();
    $amountPaid = (float)($callbackData['coinAmount'] / 100);

    if ($orderStatus === 'SUCCESS' && $operationStatus === 'SUCCESS') {

        $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

        checkCbTransID($operationId);
        addInvoicePayment($invoiceId, $operationId, $amountPaid, 0, $gatewayModuleName);

        $transaction = Capsule::table('tblaccounts')
            ->where('transid', $operationId)
            ->where('invoiceid', $invoiceId)
            ->orderBy('id', 'desc')
            ->first();

        if ($transaction) {
            $checkLink = "<a href=\"../modules/gateways/alliancepay/admin_status.php?hppOrderId="
                . $hppOrderId
                . "\" target=\"_blank\" style=\"color:blue; text-decoration:underline;\">Check Order</a>";

            $newDescription = $transaction->description . " | HPP Order ID: {$hppOrderId} | " . $checkLink;

            Capsule::table('tblaccounts')
                ->where('id', $transaction->id)
                ->update(['description' => $newDescription]);
        }

        logTransaction(
            $gatewayParams['name'],
            $payload,
            "Payment Successful. HPP Order: {$hppOrderId}, 
            Operation: {$operationId}"
        );
    } else {
        logTransaction(
            $gatewayParams['name'],
            $payload,
            "Payment Status: {$orderStatus}. HPP Order: {$hppOrderId}"
        );
    }

    http_response_code(200);
    echo 'OK';

} catch (Exception $e) {
    logModuleCall($gatewayModuleName, 'Callback Error', $bodyJson, $e->getMessage());
    http_response_code(400);
    echo 'Error';
}
