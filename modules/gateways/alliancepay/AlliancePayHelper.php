<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace alliancepay;

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

    public static function getAuthDto(array $gatewayParams): AuthorizationDTO
    {
        $merchantId = $gatewayParams['merchantId'] ?? '';
        if (empty($merchantId)) {
            throw new \Exception("Merchant ID is missing in gateway configuration.");
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

    public static function getCacheKey(string $merchantId): string
    {
        return self::CACHE_KEY_PREFIX . md5($merchantId);
    }

    public static function getCachedSettings(string $merchantId): array
    {
        $cacheKey = self::getCacheKey($merchantId);
        $value = Capsule::table('tblconfiguration')->where('setting', $cacheKey)->value('value');

        return !empty($value) ? json_decode($value, true) : [];
    }

    public static function saveAuthorizationData(AuthorizationDTO $authData, string $cacheKey): void
    {
        $jsonToSave = json_encode($authData->toArray());

        if (Capsule::table('tblconfiguration')->where('setting', $cacheKey)->exists()) {
            Capsule::table('tblconfiguration')->where('setting', $cacheKey)->update(['value' => $jsonToSave]);
        } else {
            Capsule::table('tblconfiguration')->insert(['setting' => $cacheKey, 'value' => $jsonToSave]);
        }
    }
}
