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
}
