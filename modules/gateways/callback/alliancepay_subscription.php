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
require_once __DIR__ . '/../alliancepay/Subscription/autoload.php';

use AlliancePay\AlliancePayHelper;
use AlliancePay\Sdk\Exceptions\CallbackException;
use AlliancePay\Sdk\Payment\Subscription\Callback\SubscriptionCallbackHandler;
use AlliancePay\Subscription\CallbackProcessor;
use AlliancePay\Subscription\SubscriptionSchema;

$gatewayModuleName = AlliancePayHelper::GATEWAY_MODULE_NAME;
$gatewayParams = getGatewayVariables($gatewayModuleName);
if (!$gatewayParams['type']) {
    http_response_code(503);
    exit('Module Not Activated');
}

$rawBody = trim((string)file_get_contents('php://input'));

json_decode($rawBody);
if ($rawBody === '' || json_last_error() === JSON_ERROR_NONE) {
    logModuleCall($gatewayModuleName, 'Subscription Callback Rejected', $rawBody, 'Unsigned payload');
    http_response_code(400);
    exit('Error');
}

try {
    $authDto = AlliancePayHelper::getAuthDto($gatewayParams);
    $callback = (new SubscriptionCallbackHandler())->handle($authDto);
} catch (CallbackException $e) {
    logModuleCall($gatewayModuleName, 'Subscription Callback Verification Failed', $rawBody, $e->getMessage());
    http_response_code(400);
    exit('Error');
} catch (Throwable $e) {
    logModuleCall($gatewayModuleName, 'Subscription Callback Error', $rawBody, $e->getMessage());
    http_response_code(500);
    exit('Error');
}

try {
    SubscriptionSchema::ensure();
    (new CallbackProcessor($gatewayParams['name']))->process($callback);

    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    logModuleCall($gatewayModuleName, 'Subscription Callback Processing Error', $callback->getRawPayload(), $e->getMessage());
    http_response_code(500);
    echo 'Error';
}
