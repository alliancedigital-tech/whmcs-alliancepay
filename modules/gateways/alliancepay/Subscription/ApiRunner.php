<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

use AlliancePay\AlliancePayHelper;
use AlliancePay\Sdk\Services\Authorization\Dto\AuthorizationDTO;

/**
 * Class ApiRunner.
 */
class ApiRunner
{
    /**
     * @param array $gatewayParams
     * @param callable(AuthorizationDTO): mixed $call
     * @return mixed
     * @throws \Throwable
     */
    public static function run(array $gatewayParams, callable $call): mixed
    {
        return $call(AlliancePayHelper::getAuthDto($gatewayParams));
    }
}
