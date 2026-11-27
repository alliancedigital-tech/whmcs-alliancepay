<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 *
 * Public callback of Alliance Ecom with the fiscalization result (JWS-signed).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../alliancepay/vendor/autoload.php';
require_once __DIR__ . '/../alliancepay/AlliancePayHelper.php';
require_once __DIR__ . '/../alliancepay/Fiscalization/autoload.php';

use AlliancePay\AlliancePayHelper;
use AlliancePay\Fiscalization\FiscalizationRepository;
use AlliancePay\Fiscalization\FiscalizationService;
use AlliancePay\Sdk\Exceptions\CallbackException;
use AlliancePay\Sdk\Payment\Fiscalization\Callback\FiscalizationCallbackHandler;

header('Cache-Control: no-store');

$gatewayModuleName = AlliancePayHelper::GATEWAY_MODULE_NAME;
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    http_response_code(400);
    exit('Module Not Activated');
}

$bodyRaw = file_get_contents('php://input');

try {
    $authDto = AlliancePayHelper::getAuthDto($gatewayParams);
    $callback = (new FiscalizationCallbackHandler())->handle($authDto);
    $dto = $callback->getFiscalization();

    $record = $dto->getOperationId() !== '' ? FiscalizationRepository::findByOperationId($dto->getOperationId()) : null;
    if ($record === null && $dto->getMerchantRequestId() !== '') {
        $record = FiscalizationRepository::findByMerchantRequestId($dto->getMerchantRequestId());
    }

    if ($record === null) {
        logModuleCall($gatewayModuleName, 'Fiscalization callback: unknown operation', $callback->getRawPayload(), 'Rejected');
        http_response_code(400);
        exit('Error');
    }

    $changed = (new FiscalizationService($gatewayParams))->applyUpdate($record, $dto, 'callback', $callback->getRawPayload());

    logModuleCall(
        $gatewayModuleName,
        'Fiscalization callback',
        $callback->getRawPayload(),
        $changed ? 'Applied, status ' . $dto->getStatus() : 'Ignored (duplicate / stale / downgrade)'
    );

    http_response_code(200);
    echo 'OK';
} catch (CallbackException $e) {
    logModuleCall($gatewayModuleName, 'Fiscalization callback: verification failed', $bodyRaw, $e->getMessage());
    http_response_code(400);
    echo 'JWS verification failed';
} catch (Throwable $e) {
    logModuleCall($gatewayModuleName, 'Fiscalization callback error', $bodyRaw, $e->getMessage());
    http_response_code(500);
    echo 'Error';
}
