<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

if (!defined("WHMCS")) die("This file cannot be accessed directly");

require_once __DIR__ . '/alliancepay/vendor/autoload.php';
require_once __DIR__ . '/alliancepay/AlliancePayHelper.php';

use AlliancePay\Sdk\Payment\Dto\Order\OrderRequestDTO;
use AlliancePay\Sdk\Payment\Order\CreateOrder;
use \WHMCS\Database\Capsule;
use AlliancePay\Sdk\Services\RequestIdentification\GenerateRequestIdentification;
use AlliancePay\Sdk\Payment\Refund\RefundOrder;
use AlliancePay\Sdk\Payment\Dto\Refund\RefundRequestDTO;
use AlliancePay\Sdk\Services\DateTime\DateTimeImmutableProvider;
use alliancepay\AlliancePayHelper;

function alliancepay_MetaData()
{
    return [
        'DisplayName' => 'AlliancePay',
        'APIVersion' => '1.1',
        'TokenisedStorage' => false,
        'GatewayType' => 'Bank'
    ];
}

function alliancepay_config($params)
{
    $gatewaySettings = [];
    try {
        foreach (Capsule::table('tblpaymentgateways')->where('gateway', 'alliancepay')->get() as $row) {
            $gatewaySettings[$row->setting] = $row->value;
        }
    } catch (Exception $e) {
        // Ignore any errors.
    }

    $merchantId = $params['merchantId'] ?? '';

    $authData = getAuthtorizationDataHtml($merchantId);

    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'AlliancePay Bank'],
        'baseUrl' => [
            'FriendlyName' => 'API Base URL',
            'Type' => 'text',
            'Size' => '100',
            'Default' => 'https://api-ecom-prod.bankalliance.ua/',
        ],
        'merchantId' => ['FriendlyName' => 'Merchant ID', 'Type' => 'text', 'Size' => '50'],
        'serviceCode' => ['FriendlyName' => 'Service Code', 'Type' => 'text', 'Size' => '50'],
        'authenticationKey' => [
            'FriendlyName' => 'Authentication Key',
            'Type' => 'textarea',
            'Rows' => '4',
            'Description' => 'Your Authentication Key from the bank in JSON.'
        ],
        'UsageNotes' => [
            'FriendlyName' => 'Інформація про сесію',
            'Type' => 'System',
            'Value' => $authData,
        ]
    ];
}

function getAuthtorizationDataHtml(string $merchantId): string
{
    $authData = '<div style="margin-top: 5px; padding: 15px; background: #f8f9fa;'
        . ' border: 1px solid #ddd; border-radius: 4px; max-width: 600px;">';

    if (empty($merchantId)) {
        $authData .= '<span style="color: #f0ad4e; font-weight: bold;">'
            . 'Вкажіть Merchant ID та збережіть налаштування для перевірки сесії.</span>';
    } else {
        $cached = AlliancePayHelper::getCachedSettings($merchantId);

        if (empty($cached)) {
            $authData .= '<span style="color: #d9534f; font-weight: bold;">'
                . 'Кеш порожній. Токени будуть згенеровані автоматично під час першого платежу.</span>';
        } else {
            $serverPublic = $cached['serverPublic'] ?? '';
            if (is_array($serverPublic)) {
                $serverPublic = json_encode($serverPublic);
            }

            $authData .= '<strong>Expiration:</strong> '
                . htmlspecialchars($cached['tokenExpirationDateTime'] ?? 'N/A') . '<br>';
            $authData .= '<strong>Device ID:</strong> '
                . htmlspecialchars($cached['deviceId'] ?? 'N/A') . '<br>';
            $authData .= '<strong>Auth Token:</strong> '
                . htmlspecialchars($cached['authToken'] ?? '') . '<br>';
            $authData .= '<strong>Refresh Token:</strong> '
                . htmlspecialchars($cached['refreshToken'] ?? '') . '<br>';
            $authData .= '<strong>Server Public (JSON):</strong><br>';
            $authData .= '<textarea readonly style="width: 100%; height: 70px; margin-top: 5px;'
                . ' font-family: monospace; font-size: 11px; background: #e9ecef;">'
                . htmlspecialchars($serverPublic) . '</textarea>';
        }
    }
    $authData .= '</div>';
    $authData .= '<script>jQuery(document).ready(function($) { '
        . '$("textarea[name=\'field[alliancepay][authenticationKey]\']").css('
        . '{"-webkit-text-security": "disc", "font-family": "monospace"}); });</script>';

    return $authData;
}

function alliancepay_link($params)
{
    if (isset($_POST['alliancepay_action']) && $_POST['alliancepay_action'] === 'create_order') {

        $maxAttempts = 2;
        $attempt = 1;
        $authDto = null;

        $amount = $params['amount'];
        $currency = $params['currency'];

        while ($attempt <= $maxAttempts) {
            try {
                if (!$authDto) {
                    $authDto = AlliancePayHelper::getAuthDto($params);
                }

                $merchantRequestId = GenerateRequestIdentification::generateRequestId();

                $coinAmount = (int)round($amount * 100);

                $callbackUrl = $params['systemurl']
                    . 'modules/gateways/callback/alliancepay.php?invoiceid='
                    . $params['invoiceid'];

                $orderData = [
                    'merchantRequestId' => $merchantRequestId,
                    'merchantId' => $authDto->getMerchantId(),
                    'hppPayType' => 'PURCHASE',
                    'coinAmount' => $coinAmount,
                    'paymentMethods' => ['CARD', 'APPLE_PAY', 'GOOGLE_PAY'],
                    'successUrl' => $params['returnurl'],
                    'failUrl' => $params['returnurl'],
                    'statusPageType' => 'STATUS_TIMER_PAGE',
                    'notificationUrl' => $callbackUrl,
                    'purpose' => 'Invoice #' . $params['invoiceid'],
                    'customerData' => [
                        'senderCustomerId' => (string)$params['clientdetails']['userid'],
                        'senderEmail' => $params['clientdetails']['email'],
                    ],
                ];

                $orderRequest = OrderRequestDTO::fromArray($orderData);
                $orderService = new CreateOrder();

                $response = $orderService->createOrder($orderRequest, $authDto);

                logTransaction(
                    $params['name'],
                    [
                        'InvoiceID' => $params['invoiceid'],
                        'RequestID' => $merchantRequestId,
                        'ConvertedAmount' => $amount . ' ' . $currency,
                    ],
                    'Order Created'
                );

                $redirectUrl = $response->getRedirectUrl();

                header("Location: " . $redirectUrl);
                exit;

            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                $isAuthError = strpos($errorMsg, '401') !== false ||
                               strpos($errorMsg, 'b_used_token') !== false ||
                               strpos($errorMsg, 'b_auth_token_expired') !== false;

                if ($isAuthError && $attempt < $maxAttempts) {
                    logModuleCall(
                        'alliancepay', "Create Order Token Invalid - Retrying",
                        $errorMsg,
                        "Attempt: $attempt"
                    );
                    $authDto = AlliancePayHelper::forceReauthorize($params);
                    $attempt++;
                    continue;
                }

                logModuleCall(
                    'alliancepay',
                    'Create Order Error',
                    isset($orderData) ? $orderData : $params,
                    $errorMsg
                );

                return '<div class="alert alert-danger">Payment gateway error. Please contact support.</div>';
            }
        }
    }

    $htmlOutput = '<form method="post" action="viewinvoice.php?id=' . $params['invoiceid'] . '">';
    $htmlOutput .= '<input type="hidden" name="alliancepay_action" value="create_order">';
    $htmlOutput .= '<button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-size: 16px;">';
    $htmlOutput .= '<i class="fas fa-credit-card"></i> ' . $params['langpaynow'];
    $htmlOutput .= '</button>';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}

function alliancepay_refund($params)
{
    $maxAttempts = 2;
    $attempt = 1;
    $authDto = null;

    while ($attempt <= $maxAttempts) {
        try {
            if (!$authDto) {
                $authDto = AlliancePayHelper::getAuthDto($params);
            }

            $operationId = $params['transid'];

            $refundData = [
                'merchantRequestId' => GenerateRequestIdentification::generateRequestId(),
                'merchantId' => $authDto->getMerchantId(),
                'operationId' => $operationId,
                'coinAmount' => (int)round($params['amount'] * 100),
                'date' => DateTimeImmutableProvider::fromString(
                    'now',
                    DateTimeImmutableProvider::KYIV_TIMEZONE
                ),
            ];

            $refundDto = RefundRequestDTO::fromArray($refundData);
            $refundService = new RefundOrder();

            $result = $refundService->createRefund($refundDto, $authDto);

            logModuleCall('alliancepay', "Refund Success (Attempt $attempt)", $refundData, $result);

            return [
                'status' => 'success',
                'rawdata' => 'Refund successful',
                'transid' => $result->getOperationId() ?? $refundData['merchantRequestId'],
            ];

        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            $isAuthError = strpos($errorMsg, '401') !== false ||
                           strpos($errorMsg, 'b_used_token') !== false ||
                           strpos($errorMsg, 'b_auth_token_expired') !== false;

            if ($isAuthError && $attempt < $maxAttempts) {
                logModuleCall('alliancepay', "Refund Token Invalid - Retrying", $errorMsg, "Attempt: $attempt");
                $authDto = AlliancePayHelper::forceReauthorize($params);
                $attempt++;
                continue;
            }

            logModuleCall('alliancepay', 'Refund Error', $errorMsg, $params['transid']);

            return [
                'status' => 'error',
                'rawdata' => $errorMsg,
                'transid' => $params['transid'],
            ];
        }
    }
}
