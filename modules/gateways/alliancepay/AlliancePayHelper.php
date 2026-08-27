<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay;

use AlliancePay\Sdk\Exceptions\AuthenticationException;
use AlliancePay\Sdk\Services\Authorization\AuthorizationService;
use AlliancePay\Sdk\Services\Authorization\Dto\AuthorizationDTO;
use DateTimeImmutable;
use Exception;
use WHMCS\Database\Capsule;

/**
 * Class AlliancePayHelper.
 */
class AlliancePayHelper
{
    public const CACHE_KEY_PREFIX = 'alliancepay_auth_cache_';

    public const GATEWAY_MODULE_NAME = 'alliancepay';

    public const CALLBACK_DESCRIPTION = 'AlliancePay callback';

    public const MAX_AUTH_RETRY_ATTEMPTS = 2;

    public const OPERATION_TYPE_PURCHASE = 'PURCHASE';

    public const OPERATION_TYPE_REFUND = 'REFUND';

    public const OPERATION_TYPE_ACCOUNT_2_ACCOUNT = 'ACCOUNT_2_ACCOUNT';

    public const HPP_PAY_TYPE_PURCHASE = 'PURCHASE';

    public const HPP_PAY_TYPE_A2A = 'A2A';

    public const HPP_PAY_TYPE_PREAUTH = 'PREAUTH';

    public const OPERATION_TYPE_PREAUTH = 'PREAUTH';

    public const OPERATION_TYPE_COMPLETION = 'COMPLETION';

    public const PREAUTH_EXP_DATE_DEFAULT = '2h';

    public const PREAUTH_EXP_DATE_OPTIONS = ['2h', '4h', '6h', '12h', '1d', '2d', '7d', '14d', '28d'];

    public const HPP_PAYMENT_METHODS = ['CARD', 'APPLE_PAY', 'GOOGLE_PAY'];

    public const DIRECT_TYPE_REDIRECT = 'REDIRECT';

    public const DIRECT_TYPE_BANK_LINK = 'BANK_LINK';

    public const STATUS_PAGE_TYPE_DEFAULT = 'STATUS_PAGE';

    public const STATUS_PAGE_TYPE_TIMER = 'STATUS_TIMER_PAGE';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_FAIL = 'FAIL';

    public const STATUS_REJECTED = 'REJECTED';

    public const A2A_PRIORITY_BANK_CODE = 'ALL_BANKS';

    public const A2A_FORBIDDEN_TRANSACTION_TYPE = 102;

    /**
     * @param array $gatewayParams
     * @return AuthorizationDTO
     * @throws AuthenticationException
     */
    public static function getAuthDto(array $gatewayParams): AuthorizationDTO
    {
        $merchantId = $gatewayParams['merchantId'] ?? '';
        if (empty($merchantId)) {
            throw new \InvalidArgumentException("Merchant ID is missing in gateway configuration.");
        }

        $config = [
            AuthorizationDTO::AUTH_PROPERTY_BASE_URL => rtrim($gatewayParams['baseUrl'] ?? '', '/'),
            AuthorizationDTO::AUTH_PROPERTY_MERCHANT_ID => $merchantId,
            AuthorizationDTO::AUTH_PROPERTY_SERVICE_CODE => $gatewayParams['serviceCode'] ?? '',
            AuthorizationDTO::AUTH_PROPERTY_AUTHENTICATION_KEY => $gatewayParams['authenticationKey'] ?? ''
        ];

        $cachedSettings = self::getCachedSettings($merchantId);
        if (!empty($cachedSettings)) {
            $config = array_merge($cachedSettings, $config);
        }

        $authService = new AuthorizationService();
        $authDto = $authService->initAuthorization($config);

        self::saveAuthorizationData($authDto, self::getCacheKey($merchantId));

        return $authDto;
    }

    /**
     * @param array $gatewayParams
     * @return AuthorizationDTO
     * @throws AuthenticationException
     */
    public static function forceReauthorize(array $gatewayParams): AuthorizationDTO
    {
        $merchantId = $gatewayParams['merchantId'] ?? '';
        $cacheKey = self::getCacheKey($merchantId);

        Capsule::table('tblconfiguration')->where('setting', $cacheKey)->delete();

        $config = [
            AuthorizationDTO::AUTH_PROPERTY_BASE_URL => rtrim($gatewayParams['baseUrl'] ?? '', '/'),
            AuthorizationDTO::AUTH_PROPERTY_MERCHANT_ID => $merchantId,
            AuthorizationDTO::AUTH_PROPERTY_SERVICE_CODE => $gatewayParams['serviceCode'] ?? '',
            AuthorizationDTO::AUTH_PROPERTY_AUTHENTICATION_KEY => $gatewayParams['authenticationKey'] ?? ''
        ];

        $authService = new AuthorizationService();
        $authDto = $authService->initAuthorization($config);

        self::saveAuthorizationData($authDto, $cacheKey);

        return $authDto;
    }

    /**
     * @param string $merchantId
     * @return string
     */
    public static function getCacheKey(string $merchantId): string
    {
        return self::CACHE_KEY_PREFIX . md5($merchantId);
    }

    /**
     * @param string $merchantId
     * @return array
     */
    public static function getCachedSettings(string $merchantId): array
    {
        $cacheKey = self::getCacheKey($merchantId);
        $value = Capsule::table('tblconfiguration')->where('setting', $cacheKey)->value('value');

        return !empty($value) ? json_decode($value, true) : [];
    }

    /**
     * @param string $merchantId
     * @return string
     */
    public static function getAuthorizationDataHtml(string $merchantId): string
    {
        $html = '<div style="margin-top: 5px; padding: 15px; background: #f8f9fa;'
            . ' border: 1px solid #ddd; border-radius: 4px; max-width: 600px;">';

        if (empty($merchantId)) {
            $html .= '<span style="color: #f0ad4e; font-weight: bold;">'
                . 'Вкажіть Merchant ID та збережіть налаштування для перевірки сесії.</span>';
        } else {
            $cached = self::getCachedSettings($merchantId);

            if (empty($cached)) {
                $html .= '<span style="color: #d9534f; font-weight: bold;">'
                    . 'Кеш порожній. Токени будуть згенеровані автоматично під час першого платежу.</span>';
            } else {
                $serverPublic = $cached['serverPublic'] ?? '';
                if (is_array($serverPublic)) {
                    $serverPublic = json_encode($serverPublic);
                }

                $html .= '<strong>Expiration:</strong> '
                    . htmlspecialchars($cached['tokenExpirationDateTime'] ?? 'N/A') . '<br>';
                $html .= '<strong>Device ID:</strong> '
                    . htmlspecialchars($cached['deviceId'] ?? 'N/A') . '<br>';
                $html .= '<strong>Auth Token:</strong> '
                    . htmlspecialchars(self::maskToken($cached['authToken'] ?? '')) . '<br>';
                $html .= '<strong>Refresh Token:</strong> '
                    . htmlspecialchars(self::maskToken($cached['refreshToken'] ?? '')) . '<br>';
                $html .= '<strong>Server Public (JSON):</strong><br>';
                $html .= '<textarea readonly style="width: 100%; height: 70px; margin-top: 5px;'
                    . ' font-family: monospace; font-size: 11px; background: #e9ecef;">'
                    . htmlspecialchars($serverPublic) . '</textarea>';
            }
        }

        $html .= '</div>';
        $html .= '<script>jQuery(document).ready(function($) { '
            . '$("textarea[name=\'field[alliancepay][authenticationKey]\']").css('
            . '{"-webkit-text-security": "disc", "font-family": "monospace"}); });</script>';

        return $html;
    }

    /**
     * @param int $invoiceId
     * @return bool
     */
    public static function isRefundForbidden(int $invoiceId): bool
    {
        $row = Capsule::table('tbltransaction_history')
            ->where('invoice_id', $invoiceId)
            ->first();

        if (!$row || empty($row->additional_information)) {
            return false;
        }

        $data = json_decode($row->additional_information, true) ?? [];
        $operations = $data['operations'] ?? [];

        foreach ($operations as $op) {
            if (
                ($op['type'] ?? '') === self::OPERATION_TYPE_ACCOUNT_2_ACCOUNT
                && ($op['status'] ?? '') === self::STATUS_SUCCESS
                && ($op['transactionType'] ?? null) === self::A2A_FORBIDDEN_TRANSACTION_TYPE
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $payload
     * @param array $operation
     * @param int $invoiceId
     * @param string $gatewayModuleName
     * @param string $orderStatus
     * @param float $amountPaid
     * @param bool $isCompleted
     * @return void
     */
    public static function saveCallbackHistory(
        array  $payload,
        array  $operation,
        int    $invoiceId,
        string $gatewayModuleName,
        string $orderStatus,
        float  $amountPaid,
        bool   $isCompleted
    ): void
    {
        $hppOrderId = $payload['hppOrderId'] ?? '';
        $operationId = $operation['operationId'] ?? null;

        $existing = Capsule::table('tbltransaction_history')
            ->where('invoice_id', $invoiceId)
            ->where('transaction_id', $hppOrderId)
            ->first();

        if ($existing) {
            $existingData = json_decode($existing->additional_information, true) ?? [];
            $operations = $existingData['operations'] ?? [];

            if ($operationId !== null) {
                $alreadyExists = false;
                foreach ($operations as $op) {
                    if (($op['operationId'] ?? null) === $operationId) {
                        $alreadyExists = true;
                        break;
                    }
                }
                if (!$alreadyExists) {
                    $operations[] = $operation;
                }
            }
            $info = $payload;
            unset($info['operation']);
            $info['operations'] = $operations;

            if (!isset($info['original_coin_amount']) && !empty($existingData['original_coin_amount'])) {
                $info['original_coin_amount'] = $existingData['original_coin_amount'];
            }

            Capsule::table('tbltransaction_history')
                ->where('invoice_id', $invoiceId)
                ->where('transaction_id', $hppOrderId)
                ->update([
                    'remote_status' => $orderStatus,
                    'completed' => (int)$isCompleted,
                    'additional_information' => json_encode($info, JSON_UNESCAPED_UNICODE),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } else {
            $operations = $operationId !== null ? [$operation] : [];

            $info = $payload;
            unset($info['operation']);
            $info['operations'] = $operations;

            Capsule::table('tbltransaction_history')->insert([
                'invoice_id' => $invoiceId,
                'gateway' => $gatewayModuleName,
                'transaction_id' => $hppOrderId,
                'remote_status' => $orderStatus,
                'completed' => (int)$isCompleted,
                'description' => self::CALLBACK_DESCRIPTION,
                'additional_information' => json_encode($info, JSON_UNESCAPED_UNICODE),
                'amount' => $amountPaid,
                'currency_id' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @param string $option
     * @return int
     */
    public static function getPreAuthExpSeconds(string $option): int
    {
        $map = [
            '2h' => 7200,
            '4h' => 14400,
            '6h' => 21600,
            '12h' => 43200,
            '1d' => 86400,
            '2d' => 172800,
            '7d' => 604800,
            '14d' => 1209600,
            '28d' => 2419200,
        ];

        return $map[$option] ?? 7200;
    }

    /**
     * @param int $invoiceId
     * @param string $hppOrderId
     * @param string $merchantRequestId
     * @param int $coinAmount
     * @param string $gatewayModuleName
     * @return void
     */
    public static function savePreAuthOrderData(
        int    $invoiceId,
        string $hppOrderId,
        string $merchantRequestId,
        int    $coinAmount,
        string $gatewayModuleName
    ): void
    {
        $existing = Capsule::table('tbltransaction_history')
            ->where('invoice_id', $invoiceId)
            ->where('transaction_id', $hppOrderId)
            ->first();

        if ($existing) {
            return;
        }

        $info = [
            'hppPayType' => self::HPP_PAY_TYPE_PREAUTH,
            'original_coin_amount' => $coinAmount,
            'merchantRequestId' => $merchantRequestId,
            'hppOrderId' => $hppOrderId,
            'operations' => [],
        ];

        Capsule::table('tbltransaction_history')->insert([
            'invoice_id' => $invoiceId,
            'gateway' => $gatewayModuleName,
            'transaction_id' => $hppOrderId,
            'remote_status' => self::STATUS_PENDING,
            'completed' => 0,
            'description' => 'AlliancePay PREAUTH order created',
            'additional_information' => json_encode($info, JSON_UNESCAPED_UNICODE),
            'amount' => $coinAmount / 100,
            'currency_id' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param int $invoiceId
     * @return object|null
     */
    public static function getPreAuthPendingRecord(int $invoiceId): ?object
    {
        return Capsule::table('tbltransaction_history')
            ->where('invoice_id', $invoiceId)
            ->where('completed', 0)
            ->where('additional_information', 'like', '%"hppPayType":"PREAUTH"%')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * @param int $invoiceId
     * @param string $hppOrderId
     * @return void
     */
    public static function markPreAuthCompleted(int $invoiceId, string $hppOrderId): void
    {
        Capsule::table('tbltransaction_history')
            ->where('invoice_id', $invoiceId)
            ->where('transaction_id', $hppOrderId)
            ->update([
                'completed' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * @param string $token
     * @return string
     */
    private static function maskToken(string $token): string
    {
        if (strlen($token) <= 8) {
            return str_repeat('*', strlen($token));
        }

        return substr($token, 0, 8) . '...' . substr($token, -4);
    }

    /**
     * @return void
     */
    public static function ensureTablesExist(): void
    {
        $flag = Capsule::table('tblconfiguration')
            ->where('setting', 'alliancepay_tables_created')
            ->value('value');

        if ($flag === '1') {
            return;
        }

        Capsule::statement("
            CREATE TABLE IF NOT EXISTS `alliance_checkout_integration_order` (
                `entity_id`                   INT UNSIGNED    NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
                `order_id`                    INT             NOT NULL                COMMENT 'Order ID',
                `merchant_request_id`         VARCHAR(255)    NOT NULL                COMMENT 'Merchant Request ID',
                `hpp_order_id`                VARCHAR(255)    NOT NULL                COMMENT 'HPP Order ID',
                `merchant_id`                 VARCHAR(255)    NOT NULL                COMMENT 'Merchant ID',
                `coin_amount`                 INT             NOT NULL                COMMENT 'Coin Amount',
                `hpp_pay_type`                VARCHAR(50)     NOT NULL                COMMENT 'HPP Pay Type',
                `order_status`                VARCHAR(50)     NOT NULL                COMMENT 'Order Status',
                `payment_methods`             TEXT            NOT NULL                COMMENT 'Payment Methods',
                `create_date`                 DATETIME        NOT NULL                COMMENT 'Create Date',
                `updated_at`                  DATETIME        NULL                    COMMENT 'Updated At',
                `operation_id`                VARCHAR(255)    NULL                    COMMENT 'Operation ID',
                `transaction_type`            SMALLINT UNSIGNED NULL                  COMMENT 'transactionType',
                `ecom_order_id`               VARCHAR(255)    NULL                    COMMENT 'Ecom Order ID',
                `is_callback_returned`        TINYINT(1)      NOT NULL DEFAULT 0      COMMENT 'Is Callback Returned',
                `callback_data`               TEXT            NULL                    COMMENT 'Callback Data',
                `expired_order_date`          DATETIME        NOT NULL                COMMENT 'Expired Order Date',
                `original_authorized_amount`  INT UNSIGNED    NULL                    COMMENT 'Original Authorized Amount in coins (for PREAUTH)',
                `currency_code`               SMALLINT UNSIGNED NULL                  COMMENT 'Currency ISO 4217 numeric code (980=UAH, 840=USD, 978=EUR)',
                `conversion_rate`             DECIMAL(15,6)   NULL                    COMMENT 'Currency conversion rate to UAH from PREAUTH callback',
                PRIMARY KEY (`entity_id`),
                INDEX `idx_merchant_request_id` (`merchant_request_id`) USING BTREE,
                INDEX `idx_hpp_order_id`         (`hpp_order_id`)        USING BTREE,
                INDEX `idx_merchant_id`          (`merchant_id`)         USING BTREE,
                INDEX `idx_order_id`             (`order_id`)            USING BTREE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Alliance Checkout Integration Order Table'
        ");

        Capsule::statement("
            CREATE TABLE IF NOT EXISTS `alliance_integration_order_refund` (
                `refund_id`                   INT             NOT NULL AUTO_INCREMENT COMMENT 'Refund ID',
                `order_id`                    INT             NOT NULL                COMMENT 'Order ID',
                `type`                        VARCHAR(255)    NOT NULL                COMMENT 'Type',
                `rrn`                         VARCHAR(255)    NOT NULL                COMMENT 'RRN',
                `purpose`                     VARCHAR(255)    NOT NULL                COMMENT 'Purpose',
                `comment`                     VARCHAR(255)    NOT NULL                COMMENT 'Comment',
                `coin_amount`                 INT             NOT NULL                COMMENT 'Coin Amount',
                `merchant_id`                 VARCHAR(255)    NOT NULL                COMMENT 'Merchant ID',
                `operation_id`                VARCHAR(255)    NOT NULL                COMMENT 'Operation ID',
                `ecom_operation_id`           VARCHAR(255)    NOT NULL                COMMENT 'Ecom Operation ID',
                `merchant_name`               VARCHAR(255)    NULL                    COMMENT 'Merchant Name',
                `approval_code`               VARCHAR(255)    NOT NULL                COMMENT 'Approval Code',
                `status`                      VARCHAR(255)    NOT NULL                COMMENT 'Status',
                `transaction_type`            INT             NOT NULL                COMMENT 'Transaction Type',
                `merchant_request_id`         VARCHAR(255)    NOT NULL                COMMENT 'Merchant Request ID',
                `transaction_currency`        VARCHAR(255)    NOT NULL                COMMENT 'Transaction Currency',
                `merchant_commission`         INT             NULL                    COMMENT 'Merchant Commission',
                `create_date_time`            DATETIME        NOT NULL                COMMENT 'Create Date Time',
                `modification_date_time`      DATETIME        NOT NULL                COMMENT 'Modification Date Time',
                `action_code`                 VARCHAR(255)    NOT NULL                COMMENT 'Action Code',
                `response_code`               VARCHAR(255)    NOT NULL                COMMENT 'Response Code',
                `description`                 VARCHAR(255)    NOT NULL                COMMENT 'Description',
                `processing_merchant_id`      VARCHAR(255)    NOT NULL                COMMENT 'Processing Merchant ID',
                `processing_terminal_id`      VARCHAR(255)    NOT NULL                COMMENT 'Processing Terminal ID',
                `transaction_response_info`   TEXT            NOT NULL                COMMENT 'Transaction Response Info',
                `bank_code`                   VARCHAR(255)    NOT NULL                COMMENT 'Bank Code',
                `payment_system`              VARCHAR(255)    NOT NULL                COMMENT 'Payment System',
                `product_type`                VARCHAR(255)    NOT NULL                COMMENT 'Product Type',
                `notification_url`            VARCHAR(255)    NOT NULL                COMMENT 'Notification URL',
                `payment_service_type`        VARCHAR(255)    NOT NULL                COMMENT 'Payment Service Type',
                `notification_encryption`     VARCHAR(255)    NOT NULL                COMMENT 'Notification Encryption',
                `original_operation_id`       VARCHAR(255)    NOT NULL                COMMENT 'Original Operation ID',
                `original_coin_amount`        INT             NOT NULL                COMMENT 'Original Coin Amount',
                `original_ecom_operation_id`  VARCHAR(255)    NOT NULL                COMMENT 'Original Ecom Operation ID',
                `rrn_original`                VARCHAR(255)    NOT NULL                COMMENT 'RRN Original',
                PRIMARY KEY (`refund_id`),
                INDEX `idx_merchant_request_id` (`merchant_request_id`) USING BTREE,
                INDEX `idx_merchant_id`          (`merchant_id`)         USING BTREE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Alliance Integration Order Refund Table'
        ");

        Capsule::table('tblconfiguration')->updateOrInsert(
            ['setting' => 'alliancepay_tables_created'],
            ['value' => '1']
        );
    }

    /**
     * @param AuthorizationDTO $authData
     * @param string $cacheKey
     * @return void
     */
    public static function saveAuthorizationData(AuthorizationDTO $authData, string $cacheKey): void
    {
        $jsonToSave = json_encode($authData->toArray());

        Capsule::table('tblconfiguration')->updateOrInsert(
            ['setting' => $cacheKey],
            ['value' => $jsonToSave]
        );
    }

    /**
     * @param int $orderId
     * @param string $merchantRequestId
     * @param string $hppOrderId
     * @param string $merchantId
     * @param int $coinAmount
     * @param string $hppPayType
     * @param string $orderStatus
     * @param array $paymentMethods
     * @param string $createDate
     * @param string $expiredOrderDate
     * @param int $currencyCode
     * @return void
     */
    public static function saveCheckoutOrder(
        int    $orderId,
        string $merchantRequestId,
        string $hppOrderId,
        string $merchantId,
        int    $coinAmount,
        string $hppPayType,
        string $orderStatus,
        array  $paymentMethods,
        string $createDate,
        string $expiredOrderDate,
        int    $currencyCode
    ): void
    {
        $existing = Capsule::table('alliance_checkout_integration_order')
            ->where('hpp_order_id', $hppOrderId)
            ->first();

        if ($existing) {
            return;
        }

        Capsule::table('alliance_checkout_integration_order')->insert([
            'order_id' => $orderId,
            'merchant_request_id' => $merchantRequestId,
            'hpp_order_id' => $hppOrderId,
            'merchant_id' => $merchantId,
            'coin_amount' => $coinAmount,
            'hpp_pay_type' => $hppPayType,
            'order_status' => $orderStatus,
            'payment_methods' => json_encode($paymentMethods, JSON_UNESCAPED_UNICODE),
            'create_date' => $createDate,
            'expired_order_date' => $expiredOrderDate,
            'currency_code' => $currencyCode,
            'is_callback_returned' => 0,
        ]);
    }

    /**
     * @param string $hppOrderId
     * @param string $orderStatus
     * @param string|null $operationId
     * @param int|null $transactionType
     * @param string|null $ecomOrderId
     * @param array $callbackData
     * @param int|null $originalAuthorizedAmount
     * @param float|null $conversionRate
     * @return void
     */
    public static function updateCheckoutOrderOnCallback(
        string  $hppOrderId,
        string  $orderStatus,
        ?string $operationId,
        ?int    $transactionType,
        ?string $ecomOrderId,
        array   $callbackData,
        ?int    $originalAuthorizedAmount,
        ?float  $conversionRate
    ): void
    {
        $existingJson = Capsule::table('alliance_checkout_integration_order')
            ->where('hpp_order_id', $hppOrderId)
            ->value('callback_data');

        $stored = $existingJson ? (json_decode($existingJson, true) ?? []) : [];
        $operations = $stored['operations'] ?? [];

        $incomingOperation = $callbackData['operation'] ?? null;
        if (is_array($incomingOperation) && !empty($incomingOperation['operationId'])) {
            $alreadyStored = false;
            foreach ($operations as $op) {
                if (($op['operationId'] ?? null) === $incomingOperation['operationId']) {
                    $alreadyStored = true;
                    break;
                }
            }
            if (!$alreadyStored) {
                $operations[] = $incomingOperation;
            }
        }

        $callbackDataToStore = $callbackData;
        unset($callbackDataToStore['operation']);
        $callbackDataToStore['operations'] = $operations;

        $update = [
            'order_status' => $orderStatus,
            'is_callback_returned' => 1,
            'callback_data' => json_encode($callbackDataToStore, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($operationId !== null) {
            $update['operation_id'] = $operationId;
        }
        if ($transactionType !== null) {
            $update['transaction_type'] = $transactionType;
        }
        if ($ecomOrderId !== null) {
            $update['ecom_order_id'] = $ecomOrderId;
        }
        if ($originalAuthorizedAmount !== null) {
            $update['original_authorized_amount'] = $originalAuthorizedAmount;
        }
        if ($conversionRate !== null) {
            $update['conversion_rate'] = $conversionRate;
        }

        Capsule::table('alliance_checkout_integration_order')
            ->where('hpp_order_id', $hppOrderId)
            ->update($update);
    }

    /**
     * @param int $orderId
     * @return object|null
     */
    public static function getCheckoutOrderByInvoice(int $orderId): ?object
    {
        return Capsule::table('alliance_checkout_integration_order')
            ->where('order_id', $orderId)
            ->orderBy('entity_id', 'desc')
            ->first();
    }

    /**
     * @param int $orderId
     * @param array $refundData
     * @param string $merchantRequestId
     * @return void
     * @throws Exception
     */
    public static function saveRefundOrder(
        int    $orderId,
        array  $refundData,
        string $merchantRequestId
    ): void
    {
        $toDatetime = static function (?string $value): string {
            if (empty($value)) {
                return date('Y-m-d H:i:s');
            }
            try {
                return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                return date('Y-m-d H:i:s');
            }
        };

        Capsule::table('alliance_integration_order_refund')->insert([
            'order_id' => $orderId,
            'type' => $refundData['type'] ?? '',
            'rrn' => $refundData['rrn'] ?? '',
            'purpose' => 'Refund for invoice #' . $orderId,
            'comment' => '',
            'coin_amount' => (int)($refundData['coinAmount'] ?? 0),
            'merchant_id' => $refundData['merchantId'] ?? '',
            'operation_id' => $refundData['operationId'] ?? '',
            'ecom_operation_id' => $refundData['ecomOperationId'] ?? '',
            'merchant_name' => $refundData['merchantName'] ?? null,
            'approval_code' => $refundData['approvalCode'] ?? '',
            'status' => $refundData['status'] ?? '',
            'transaction_type' => (int)($refundData['transactionType'] ?? 0),
            'merchant_request_id' => $merchantRequestId,
            'transaction_currency' => $refundData['transactionCurrency'] ?? '',
            'merchant_commission' => isset($refundData['merchantCommission'])
                ? (int)round((float)$refundData['merchantCommission'])
                : null,
            'create_date_time' => $toDatetime($refundData['creationDateTime'] ?? null),
            'modification_date_time' => $toDatetime($refundData['modificationDateTime'] ?? null),
            'action_code' => '',
            'response_code' => '',
            'description' => '',
            'processing_merchant_id' => $refundData['processingMerchantId'] ?? '',
            'processing_terminal_id' => $refundData['processingTerminalId'] ?? '',
            'transaction_response_info' => json_encode(
                $refundData['transactionResponseInfo'] ?? [],
                JSON_UNESCAPED_UNICODE
            ),
            'bank_code' => $refundData['bankCode'] ?? '',
            'payment_system' => $refundData['paymentSystem'] ?? '',
            'product_type' => $refundData['productType'] ?? '',
            'notification_url' => $refundData['notificationUrl'] ?? '',
            'payment_service_type' => $refundData['paymentServiceType'] ?? '',
            'notification_encryption' => $refundData['notificationEncryption'] ? '1' : '0',
            'original_operation_id' => $refundData['originalOperationId'] ?? '',
            'original_coin_amount' => (int)($refundData['originalCoinAmount'] ?? 0),
            'original_ecom_operation_id' => $refundData['originalEcomOperationId'] ?? '',
            'rrn_original' => $refundData['rrnOriginal'] ?? '',
        ]);
    }
}
