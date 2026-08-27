<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/alliancepay/vendor/autoload.php';
require_once __DIR__ . '/alliancepay/AlliancePayHelper.php';

use AlliancePay\Sdk\Payment\Dto\Order\OrderRequestDTO;
use AlliancePay\Sdk\Payment\Order\CreateOrder;
use WHMCS\Database\Capsule;
use League\ISO3166\ISO3166;
use AlliancePay\Sdk\Services\RequestIdentification\GenerateRequestIdentification;
use AlliancePay\Sdk\Payment\Refund\RefundOrder;
use AlliancePay\Sdk\Payment\Dto\Refund\RefundRequestDTO;
use AlliancePay\Sdk\Services\DateTime\DateTimeImmutableProvider;
use AlliancePay\AlliancePayHelper;
use AlliancePay\Sdk\Exceptions\AuthenticationException;
use AlliancePay\Sdk\Traits\CoinAmountConverter;

function alliancepay_MetaData()
{
    return [
        'DisplayName' => 'AlliancePay',
        'APIVersion' => '1.1',
        'TokenisedStorage' => false,
        'gatewayType' => 'Bank',
    ];
}

function alliancepay_config($params)
{
    AlliancePayHelper::ensureTablesExist();

    $gatewaySettings = [];
    try {
        $settings = Capsule::table('tblpaymentgateways')
            ->where('gateway', AlliancePayHelper::GATEWAY_MODULE_NAME)
            ->get();
        foreach ($settings as $row) {
            $gatewaySettings[$row->setting] = $row->value;
        }
    } catch (Exception $e) {
        logModuleCall(AlliancePayHelper::GATEWAY_MODULE_NAME, 'Config DB Error', [], $e->getMessage());
    }

    $merchantId = $params['merchantId'] ?? '';

    $authData = AlliancePayHelper::getAuthorizationDataHtml($merchantId);

    $currentPaymentType = $gatewaySettings['paymentType'] ?? AlliancePayHelper::HPP_PAY_TYPE_PURCHASE;
    $currentConvertTo   = $gatewaySettings['convertto'] ?? '';

    $a2aWarningHtml = '';
    if ($currentPaymentType === AlliancePayHelper::HPP_PAY_TYPE_A2A) {
        try {
            $uahCurrencyId = Capsule::table('tblcurrencies')->where('code', 'UAH')->value('id');
            if ((string)$currentConvertTo !== (string)$uahCurrencyId) {
                $a2aWarningHtml = '<div style="padding:12px 15px; background:#fff3cd; border:1px solid #ffc107;'
                    . ' border-radius:4px; color:#856404; max-width:600px;">'
                    . '<strong>&#9888; Увага!</strong> Payment Type встановлено <strong>A2A</strong>, '
                    . 'але <strong>Convert To For Processing</strong> не встановлено в <strong>UAH</strong>.<br>'
                    . 'A2A підтримує тільки UAH. Будь ласка, встановіть '
                    . '<strong>Convert To For Processing &rarr; UAH</strong> у налаштуваннях цього gateway.'
                    . '</div>';
            }
        } catch (Exception $e) {
            logModuleCall(AlliancePayHelper::GATEWAY_MODULE_NAME, 'Config A2A Warning Error', [], $e->getMessage());
        }
    }

    $config = [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'AlliancePay Bank'],
        'baseUrl' => [
            'FriendlyName' => 'API Base URL',
            'Type' => 'text',
            'Size' => '100',
            'Default' => 'https://api-ecom-prod.bankalliance.ua/',
        ],
        'paymentType' => [
            'FriendlyName' => 'Payment Type',
            'Type' => 'dropdown',
            'Options' => [
                AlliancePayHelper::HPP_PAY_TYPE_PURCHASE => AlliancePayHelper::HPP_PAY_TYPE_PURCHASE,
                AlliancePayHelper::HPP_PAY_TYPE_A2A => AlliancePayHelper::HPP_PAY_TYPE_A2A,
                AlliancePayHelper::HPP_PAY_TYPE_PREAUTH => AlliancePayHelper::HPP_PAY_TYPE_PREAUTH,
            ],
            'Default' => AlliancePayHelper::HPP_PAY_TYPE_PURCHASE,
        ],
        'preAuthExpDate' => [
            'FriendlyName' => 'Pre-Auth Expiration Period',
            'Type' => 'dropdown',
            'Options' => implode(',', AlliancePayHelper::PREAUTH_EXP_DATE_OPTIONS),
            'Default' => AlliancePayHelper::PREAUTH_EXP_DATE_DEFAULT,
            'Description' => 'Термін дії pre-auth резервування (мін 2год, макс 28 днів). Застосовується лише при Payment Type = PREAUTH.',
        ],
        'statusPageType' => [
            'FriendlyName' => 'Status Page Type',
            'Type' => 'dropdown',
            'Options' => [
                AlliancePayHelper::STATUS_PAGE_TYPE_DEFAULT => AlliancePayHelper::STATUS_PAGE_TYPE_DEFAULT,
                AlliancePayHelper::STATUS_PAGE_TYPE_TIMER => AlliancePayHelper::STATUS_PAGE_TYPE_TIMER,
            ],
            'Default' => AlliancePayHelper::STATUS_PAGE_TYPE_DEFAULT,
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

    if ($a2aWarningHtml !== '') {
        $config['A2AWarning'] = [
            'FriendlyName' => 'A2A Warning',
            'Type' => 'System',
            'Value' => $a2aWarningHtml,
        ];
    }

    return $config;
}

function alliancepay_link($params)
{
    if (isset($_POST['alliancepay_action']) && $_POST['alliancepay_action'] === 'create_order') {

        $maxAttempts = AlliancePayHelper::MAX_AUTH_RETRY_ATTEMPTS;
        $attempt = 1;
        $authDto = null;

        $amount = $params['amount'];
        $currency = $params['currency'];

        $currencyCode = CoinAmountConverter::currencyCodeFromAlpha($currency)
            ?? OrderRequestDTO::CURRENCY_UAH;

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

                $phone = '';
                $phoneCc = $params['clientdetails']['phonecc'];
                $phoneNumber = $params['clientdetails']['phonenumber'];
                if ($phoneCc && $phoneNumber) {
                    $phone = $phoneCc . $phoneNumber;
                }

                $countryCode = '';
                if (!empty($params['clientdetails']['countrycode'])) {
                    $leagueIso3166 = new ISO3166();
                    $countryData = $leagueIso3166->alpha2($params['clientdetails']['countrycode']);
                    $countryCode = $countryData['numeric'] ?? '';
                }

                $hppPayType = $params['paymentType'] ?? AlliancePayHelper::HPP_PAY_TYPE_PURCHASE;

                $effectiveCurrencyCode = ($hppPayType === AlliancePayHelper::HPP_PAY_TYPE_A2A)
                    ? OrderRequestDTO::CURRENCY_UAH
                    : $currencyCode;

                $orderData = [
                    'merchantRequestId' => $merchantRequestId,
                    'merchantId' => $authDto->getMerchantId(),
                    'hppPayType' => $hppPayType,
                    'coinAmount' => $coinAmount,
                    'currencyCode' => $effectiveCurrencyCode,
                    'directType' => AlliancePayHelper::DIRECT_TYPE_REDIRECT,
                    'paymentMethods' => AlliancePayHelper::HPP_PAYMENT_METHODS,
                    'successUrl' => $hppPayType === AlliancePayHelper::HPP_PAY_TYPE_PREAUTH
                        ? rtrim($params['systemurl'], '/') . '/viewinvoice.php?id=' . $params['invoiceid']
                        : $params['returnurl'],
                    'failUrl' => $params['returnurl'],
                    'statusPageType' => $params['statusPageType'] ?? AlliancePayHelper::STATUS_PAGE_TYPE_DEFAULT,
                    'notificationUrl' => $callbackUrl,
                    'purpose' => 'Invoice #' . $params['invoiceid'],
                    'customerData' => [
                        'senderCustomerId' => (string)($params['clientdetails']['userid'] ?? ''),
                        'senderFirstName' => $params['clientdetails']['firstname'] ?? '',
                        'senderLastName' => $params['clientdetails']['lastname'] ?? '',
                        'senderEmail' => $params['clientdetails']['email'] ?? '',
                        'senderRegion' => $params['clientdetails']['state'] ?? '',
                        'senderCity' => $params['clientdetails']['city'] ?? '',
                        'senderStreet' => $params['clientdetails']['address1'] ?? '',
                        'senderAdditionalAddress' => $params['clientdetails']['address2'] ?? '',
                        'senderZipCode' => $params['clientdetails']['postcode'] ?? '',
                        'senderPhone' => $phone,
                    ],
                ];

                if ($hppPayType === AlliancePayHelper::HPP_PAY_TYPE_A2A) {
                    $orderData['directType'] = AlliancePayHelper::DIRECT_TYPE_BANK_LINK;
                    $orderData['priorityBankCode'] = AlliancePayHelper::A2A_PRIORITY_BANK_CODE;
                    $orderData['merchantComment'] = 'Payment for invoice #' . ($params['invoiceid'] ?? '');
                }

                if ($hppPayType === AlliancePayHelper::HPP_PAY_TYPE_PREAUTH) {
                    $expSeconds = AlliancePayHelper::getPreAuthExpSeconds(
                        $params['preAuthExpDate'] ?? AlliancePayHelper::PREAUTH_EXP_DATE_DEFAULT
                    );
                    $expDt = (new DateTimeImmutable('now', new DateTimeZone('Europe/Kyiv')))
                        ->modify('+' . ($expSeconds + 30) . ' seconds');
                    $orderData['preAuthExpDate'] = preg_replace(
                        '/\.(\d{2})\d+([+-])/',
                        '.$1$2',
                        $expDt->format('Y-m-d H:i:s.vP')
                    );
                }

                if (!empty($countryCode)) {
                    $orderData['customerData']['senderCountry'] = $countryCode;
                }

                $orderRequest = OrderRequestDTO::fromArray($orderData);
                $orderService = new CreateOrder();

                $response = $orderService->createOrder($orderRequest, $authDto);

                $expiredOrderDate = $response->getExpiredOrderDate()
                    ? $response->getExpiredOrderDate()->format('Y-m-d H:i:s')
                    : date('Y-m-d H:i:s', strtotime('+24 hours'));

                $createDate = $response->getCreateDate()
                    ? $response->getCreateDate()->format('Y-m-d H:i:s')
                    : date('Y-m-d H:i:s');

                AlliancePayHelper::saveCheckoutOrder(
                    orderId: (int)$params['invoiceid'],
                    merchantRequestId: $merchantRequestId,
                    hppOrderId: $response->getHppOrderId(),
                    merchantId: $authDto->getMerchantId(),
                    coinAmount: $coinAmount,
                    hppPayType: $hppPayType,
                    orderStatus: $response->getOrderStatus(),
                    paymentMethods: $response->getPaymentMethods(),
                    createDate: $createDate,
                    expiredOrderDate: $expiredOrderDate,
                    currencyCode: $effectiveCurrencyCode,
                );

                if ($hppPayType === AlliancePayHelper::HPP_PAY_TYPE_PREAUTH) {
                    AlliancePayHelper::savePreAuthOrderData(
                        invoiceId: (int)$params['invoiceid'],
                        hppOrderId: $response->getHppOrderId(),
                        merchantRequestId: $merchantRequestId,
                        coinAmount: $coinAmount,
                        gatewayModuleName: AlliancePayHelper::GATEWAY_MODULE_NAME,
                    );
                }

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

                if (!str_starts_with($redirectUrl, 'https://')) {
                    throw new \UnexpectedValueException('Invalid redirect URL received from payment gateway.');
                }

                header("Location: " . $redirectUrl);
                exit;

            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                $isAuthError = $e->getPrevious() instanceof AuthenticationException
                    || strpos($errorMsg, '401') !== false
                    || strpos($errorMsg, 'b_used_token') !== false
                    || strpos($errorMsg, 'b_auth_token_expired') !== false;

                if ($isAuthError && $attempt < $maxAttempts) {
                    logModuleCall(
                        AlliancePayHelper::GATEWAY_MODULE_NAME, "Create Order Token Invalid - Retrying",
                        $errorMsg,
                        "Attempt: $attempt"
                    );
                    $authDto = AlliancePayHelper::forceReauthorize($params);
                    $attempt++;
                    continue;
                }

                logModuleCall(
                    AlliancePayHelper::GATEWAY_MODULE_NAME,
                    'Create Order Error',
                    $orderData ?? $params,
                    $errorMsg
                );

                return '<div class="alert alert-danger">Payment gateway error. Please contact support.</div>';
            }
        }
    }

    $existingPreAuth = AlliancePayHelper::getPreAuthPendingRecord((int)$params['invoiceid']);
    if ($existingPreAuth) {
        return '<div class="alert alert-warning" style="margin: 10px 0;">'
            . '<strong>AlliancePay:</strong> '
            . 'Кошти зарезервовано та очікують підтвердження адміністратора.'
            . '</div>';
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
    if (AlliancePayHelper::isRefundForbidden((int)$params['invoiceid'])) {
        logModuleCall(
            AlliancePayHelper::GATEWAY_MODULE_NAME,
            'Refund Forbidden',
            ['invoiceid' => $params['invoiceid'], 'transid' => $params['transid']],
            'Refund is not allowed for ACCOUNT_2_ACCOUNT transactions (transactionType 102).'
        );

        return [
            'status' => 'error',
            'rawdata' => 'Refund is not allowed for this transaction type.',
            'transid' => $params['transid'],
        ];
    }

    $maxAttempts = AlliancePayHelper::MAX_AUTH_RETRY_ATTEMPTS;
    $attempt = 1;
    $authDto = null;
    $checkoutOrder = AlliancePayHelper::getCheckoutOrderByInvoice((int)$params['invoiceid']);
    $savedCurrencyCode = $checkoutOrder ? (int)$checkoutOrder->currency_code : OrderRequestDTO::CURRENCY_UAH;
    $conversionRate    = $checkoutOrder ? (float)$checkoutOrder->conversion_rate : null;

    if ($savedCurrencyCode !== OrderRequestDTO::CURRENCY_UAH && $conversionRate) {
        $refundCoinAmount = CoinAmountConverter::convertToUahCoins(
            (float)$params['amount'], $conversionRate
        );
    } else {
        $refundCoinAmount = (int)round($params['amount'] * 100);
    }

    while ($attempt <= $maxAttempts) {
        try {
            if (!$authDto) {
                $authDto = AlliancePayHelper::getAuthDto($params);
            }

            $operationId      = $params['transid'];
            $refundMerchantId = GenerateRequestIdentification::generateRequestId();

            $refundData = [
                'merchantRequestId' => $refundMerchantId,
                'merchantId' => $authDto->getMerchantId(),
                'operationId' => $operationId,
                'coinAmount' => $refundCoinAmount,
                'date' => DateTimeImmutableProvider::fromString(
                    'now',
                    DateTimeImmutableProvider::KYIV_TIMEZONE
                ),
            ];

            $refundDto = RefundRequestDTO::fromArray($refundData);
            $refundService = new RefundOrder();

            $result = $refundService->createRefund($refundDto, $authDto);

            logModuleCall(AlliancePayHelper::GATEWAY_MODULE_NAME, "Refund Success (Attempt $attempt)", $refundData, $result);

            AlliancePayHelper::saveRefundOrder(
                orderId: (int)$params['invoiceid'],
                refundData: $result->toArray(),
                merchantRequestId: $refundMerchantId,
            );

            return [
                'status' => 'success',
                'rawdata' => 'Refund successful',
                'transid' => $result->getOperationId() ?? $refundMerchantId,
            ];

        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            $isAuthError = $e->getPrevious() instanceof AuthenticationException
                || strpos($errorMsg, '401') !== false
                || strpos($errorMsg, 'b_used_token') !== false
                || strpos($errorMsg, 'b_auth_token_expired') !== false;

            if ($isAuthError && $attempt < $maxAttempts) {
                logModuleCall(AlliancePayHelper::GATEWAY_MODULE_NAME, "Refund Token Invalid - Retrying", $errorMsg, "Attempt: $attempt");
                $authDto = AlliancePayHelper::forceReauthorize($params);
                $attempt++;
                continue;
            }

            logModuleCall(AlliancePayHelper::GATEWAY_MODULE_NAME, 'Refund Error', $errorMsg, $params['transid']);

            return [
                'status' => 'error',
                'rawdata' => $errorMsg,
                'transid' => $params['transid'],
            ];
        }
    }
}
