<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Fiscalization;

use AlliancePay\AlliancePayHelper;
use Throwable;

/**
 * Class AutoDispatcher.
 */
class AutoDispatcher
{
    /** @var array[] */
    private static array $queue = [];

    private static bool $registered = false;

    /**
     * @param array $gatewayParams
     * @param int $invoiceId
     * @param string $operationId
     * @param string $type PURCHASE|REFUND
     * @param int $coins UAH kopecks
     * @param string|null $originalOperationId REFUND only
     * @return void
     */
    public static function schedule(
        array $gatewayParams,
        int $invoiceId,
        string $operationId,
        string $type,
        int $coins,
        ?string $originalOperationId = null
    ): void {
        try {
            if ($operationId === '' || !(new FiscalizationSettings($gatewayParams))->isAuto()) {
                return;
            }

            self::$queue[] = compact('gatewayParams', 'invoiceId', 'operationId', 'type', 'coins', 'originalOperationId');

            if (!self::$registered) {
                self::$registered = true;
                register_shutdown_function([self::class, 'run']);
            }
        } catch (Throwable $e) {
            logModuleCall(AlliancePayHelper::GATEWAY_MODULE_NAME, 'Fiscalization: schedule error', [], $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public static function run(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        foreach (self::$queue as $job) {
            try {
                (new FiscalizationService($job['gatewayParams']))->fiscalize(
                    $job['invoiceId'],
                    $job['operationId'],
                    $job['type'],
                    $job['coins'],
                    $job['originalOperationId']
                );
            } catch (Throwable $e) {
                logModuleCall(AlliancePayHelper::GATEWAY_MODULE_NAME, 'Fiscalization: auto error', [], $e->getMessage());
            }
        }

        self::$queue = [];
    }
}
