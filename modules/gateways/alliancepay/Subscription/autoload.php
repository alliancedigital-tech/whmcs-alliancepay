<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

require_once __DIR__ . '/../AlliancePayHelper.php';

foreach ([
    'SubscriptionConstants',
    'SubscriptionSchema',
    'Repository',
    'TemplateRepository',
    'PlanRepository',
    'SubscriptionRepository',
    'ScheduledPaymentRepository',
    'VersionHistoryRepository',
    'AuditLog',
    'ApiRunner',
    'RuleBuilder',
    'PlanKey',
    'PlanService',
    'InvoiceMatcher',
    'SubscriptionService',
    'CallbackProcessor',
    'SubscriptionDiagnostics',
] as $alliancePaySubscriptionClass) {
    require_once __DIR__ . '/' . $alliancePaySubscriptionClass . '.php';
}
unset($alliancePaySubscriptionClass);
