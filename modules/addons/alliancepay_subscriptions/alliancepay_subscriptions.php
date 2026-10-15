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
require_once __DIR__ . '/lib/AdminUi.php';

use AlliancePay\Subscription\SubscriptionSchema;
use AlliancePaySubscriptions\AdminUi;

function alliancepay_subscriptions_config()
{
    return [
        'name' => 'AlliancePay Subscriptions',
        'description' => 'Керування планами підписок та рекурентними платежами AlliancePay.',
        'version' => '1.0.0',
        'author' => 'Alliance Dgtl',
        'language' => 'english',
        'fields' => [
            'defaultCategoryIndicator' => [
                'FriendlyName' => 'Default Category Indicator',
                'Type' => 'text',
                'Size' => '50',
                'Description' => 'categorySubcategoryIndicator за замовчуванням для нових планів/шаблонів (надається банком).',
            ],
            'privilegedRoleIds' => [
                'FriendlyName' => 'Privileged Admin Role IDs',
                'Type' => 'text',
                'Size' => '30',
                'Default' => '1',
                'Description' => 'ID ролей адміністраторів (через кому), яким дозволені зміна ціни плану, архівація та Run Now.',
            ],
        ],
    ];
}

function alliancepay_subscriptions_activate()
{
    try {
        SubscriptionSchema::ensure();
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Не вдалося створити таблиці: ' . $e->getMessage()];
    }

    return ['status' => 'success', 'description' => 'Таблиці підписок створено.'];
}

function alliancepay_subscriptions_deactivate()
{
    return ['status' => 'success', 'description' => 'Модуль вимкнено. Дані підписок збережено.'];
}

function alliancepay_subscriptions_upgrade($vars)
{
    SubscriptionSchema::ensure();
}

function alliancepay_subscriptions_output($vars)
{
    (new AdminUi($vars))->dispatch();
}
