<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

require_once __DIR__ . '/../AlliancePayHelper.php';

foreach ([
    'FiscalizationSchema',
    'FiscalizationRepository',
    'ProductFiscalRepository',
    'FiscalizationSettings',
    'FiscalProductResolver',
    'ProductsBuilder',
    'FiscalizationService',
    'AutoDispatcher',
    'AdminBlock',
] as $alliancePayFiscalizationClass) {
    require_once __DIR__ . '/' . $alliancePayFiscalizationClass . '.php';
}
unset($alliancePayFiscalizationClass);
