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

use AlliancePay\AlliancePayHelper;
use AlliancePay\Sdk\Payment\Callback\CallbackHandler;
use WHMCS\Database\Capsule;

$gatewayModuleName = AlliancePayHelper::GATEWAY_MODULE_NAME;
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
    $operationArray = $callbackData['operation'];
    $operationStatus = $operationArray['status'] ?? '';
    $hppOrderId = $callbackData['hppOrderId'];
    $merchantRequestId = $callbackData['merchantRequestId'];
    $operationId = $operationArray['operationId'] ?? '';
    $amountPaid = (float)($callbackData['coinAmount'] / 100);

    $operationType = $operationArray['type'] ?? '';

    if ($orderStatus === AlliancePayHelper::STATUS_SUCCESS && $operationStatus === AlliancePayHelper::STATUS_SUCCESS) {

        if ($operationType === AlliancePayHelper::OPERATION_TYPE_REFUND) {
            logTransaction(
                $gatewayParams['name'],
                $payload,
                "Refund Successful. HPP Order: {$hppOrderId}, Operation: {$operationId}"
            );
        } elseif ($operationType === AlliancePayHelper::OPERATION_TYPE_PREAUTH) {
            logTransaction(
                $gatewayParams['name'],
                $payload,
                "PREAUTH Authorized. HPP Order: {$hppOrderId}, Operation: {$operationId}"
            );
        } elseif ($operationType === AlliancePayHelper::OPERATION_TYPE_COMPLETION) {
            $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

            checkCbTransID($operationId);
            addInvoicePayment($invoiceId, $operationId, $amountPaid, 0, $gatewayModuleName);

            AlliancePayHelper::markPreAuthCompleted((int)$invoiceId, $hppOrderId);

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
                "Completion Successful. HPP Order: {$hppOrderId}, Operation: {$operationId}"
            );
        } else {
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
                "Payment Successful. HPP Order: {$hppOrderId}, Operation: {$operationId}"
            );
        }
    } else {
        logTransaction(
            $gatewayParams['name'],
            $payload,
            "Payment Status: {$orderStatus}. HPP Order: {$hppOrderId}"
        );
    }

    AlliancePayHelper::saveCallbackHistory(
        payload: $payload,
        operation: $operationArray,
        invoiceId: (int)$invoiceId,
        gatewayModuleName: $gatewayModuleName,
        orderStatus: $orderStatus,
        amountPaid: $amountPaid,
        isCompleted: (
            $orderStatus === AlliancePayHelper::STATUS_SUCCESS
            && $operationStatus === AlliancePayHelper::STATUS_SUCCESS
            && $operationType !== AlliancePayHelper::OPERATION_TYPE_PREAUTH
        ),
    );

    $isConversionType = in_array($operationType, [
        AlliancePayHelper::OPERATION_TYPE_PURCHASE,
        AlliancePayHelper::OPERATION_TYPE_PREAUTH,
    ], true);

    AlliancePayHelper::updateCheckoutOrderOnCallback(
        hppOrderId: $hppOrderId,
        orderStatus: $orderStatus,
        operationId: $operationId ?: null,
        transactionType: isset($operationArray['transactionType'])
            ? (int)$operationArray['transactionType']
            : null,
        ecomOrderId: $callbackData['ecomOrderId'] ?? null,
        callbackData: $payload,
        originalAuthorizedAmount: (
            $operationType === AlliancePayHelper::OPERATION_TYPE_PREAUTH
            && $operationStatus === AlliancePayHelper::STATUS_SUCCESS
        )
            ? (int)($operationArray['coinAmount'] ?? 0)
            : null,
        conversionRate: ($isConversionType && isset($operationArray['conversionRate']))
            ? (float)$operationArray['conversionRate']
            : null,
    );

    http_response_code(200);
    echo 'OK';

} catch (Exception $e) {
    logModuleCall($gatewayModuleName, 'Callback Error', $bodyJson, $e->getMessage());
    http_response_code(400);
    echo 'Error';
}
