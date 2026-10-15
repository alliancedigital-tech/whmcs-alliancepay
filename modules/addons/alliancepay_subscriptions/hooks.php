<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../gateways/alliancepay/vendor/autoload.php';
require_once __DIR__ . '/../../gateways/alliancepay/AlliancePayHelper.php';
require_once __DIR__ . '/../../gateways/alliancepay/Subscription/autoload.php';

use AlliancePay\Subscription\PlanService;
use AlliancePay\Subscription\SubscriptionSchema;

add_hook('DailyCronJob', 1, function () {
    try {
        SubscriptionSchema::ensure();
        $count = PlanService::flagArchiveCandidates();
        if ($count > 0) {
            logActivity("AlliancePay subscriptions: {$count} plan(s) are candidates for archiving.");
        }
    } catch (\Throwable $e) {
        logActivity('AlliancePay subscriptions: archive candidate check failed: ' . $e->getMessage());
    }
});
