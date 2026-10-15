<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

use WHMCS\Database\Capsule;

/**
 * Class SubscriptionSchema.
 */
class SubscriptionSchema
{
    public const VERSION_SETTING = 'alliancepay_subs_schema_version';

    public const VERSION = '1';

    public const TABLE_TEMPLATE = 'alliance_sub_template';

    public const TABLE_PLAN = 'alliance_sub_plan';

    public const TABLE_SUBSCRIPTION = 'alliance_sub_subscription';

    public const TABLE_SCHEDULED_PAYMENT = 'alliance_sub_scheduled_payment';

    public const TABLE_VERSION_HISTORY = 'alliance_sub_plan_version_history';

    public const TABLE_AUDIT_LOG = 'alliance_sub_audit_log';

    /**
     * @return void
     */
    public static function ensure(): void
    {
        $current = Capsule::table('tblconfiguration')
            ->where('setting', self::VERSION_SETTING)
            ->value('value');

        if ((string)$current === self::VERSION) {
            return;
        }

        Capsule::statement("
            CREATE TABLE IF NOT EXISTS `" . self::TABLE_TEMPLATE . "` (
                `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code`               VARCHAR(100) NOT NULL,
                `name`               VARCHAR(255) NOT NULL,
                `category_indicator` VARCHAR(100) NOT NULL,
                `rules_template`     LONGTEXT     NOT NULL COMMENT 'JSON, structure without amounts',
                `status`             VARCHAR(20)  NOT NULL DEFAULT 'active' COMMENT 'active|archived',
                `created_at`         DATETIME     NOT NULL,
                `updated_at`         DATETIME     NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        Capsule::statement("
            CREATE TABLE IF NOT EXISTS `" . self::TABLE_PLAN . "` (
                `id`                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `provider_plan_id`            VARCHAR(255) NOT NULL,
                `product_id`                  INT UNSIGNED NOT NULL COMMENT 'tblproducts.id',
                `billing_cycle`               VARCHAR(30)  NOT NULL COMMENT 'WHMCS billing cycle, e.g. Monthly',
                `template_id`                 INT UNSIGNED NULL,
                `plan_key`                    CHAR(64)     NOT NULL,
                `is_active`                   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'offered to new customers',
                `active_subscriptions_count`  INT UNSIGNED NOT NULL DEFAULT 0,
                `archive_candidate`           TINYINT(1)   NOT NULL DEFAULT 0,
                `sync_status`                 VARCHAR(20)  NOT NULL DEFAULT 'SYNCED' COMMENT 'SYNCED|ERROR',
                `sync_error`                  TEXT         NULL,
                `version_id`                  VARCHAR(255) NULL,
                `merchant_id`                 VARCHAR(255) NOT NULL,
                `name`                        VARCHAR(255) NOT NULL,
                `description`                 TEXT         NULL,
                `status`                      VARCHAR(20)  NOT NULL DEFAULT 'ACTIVE' COMMENT 'ACTIVE|PAUSED|CANCELED|ERROR',
                `category_indicator`          VARCHAR(100) NOT NULL,
                `rules`                       LONGTEXT     NOT NULL COMMENT 'JSON',
                `applies_from`                DATETIME     NULL,
                `applies_to`                  DATETIME     NULL,
                `created_at`                  DATETIME     NOT NULL,
                `updated_at`                  DATETIME     NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_provider_plan_id` (`provider_plan_id`),
                UNIQUE KEY `uniq_plan_key` (`plan_key`),
                KEY `idx_product_cycle` (`product_id`, `billing_cycle`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        Capsule::statement("
            CREATE TABLE IF NOT EXISTS `" . self::TABLE_SUBSCRIPTION . "` (
                `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `service_id`          INT UNSIGNED NOT NULL COMMENT 'tblhosting.id',
                `invoice_id`          INT UNSIGNED NOT NULL COMMENT 'first invoice',
                `client_id`           INT UNSIGNED NOT NULL,
                `plan_id`             INT UNSIGNED NOT NULL,
                `rules_snapshot`      LONGTEXT     NOT NULL COMMENT 'JSON, rules at subscribe time',
                `provider_order_id`   VARCHAR(255) NULL COMMENT 'hppOrderId',
                `merchant_request_id` VARCHAR(255) NOT NULL,
                `status`              VARCHAR(20)  NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|ACTIVE|CANCELED|FAILED',
                `callback_data`       LONGTEXT     NULL,
                `created_at`          DATETIME     NOT NULL,
                `updated_at`          DATETIME     NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_provider_order_id` (`provider_order_id`),
                KEY `idx_service_id` (`service_id`),
                KEY `idx_plan_id` (`plan_id`),
                CONSTRAINT `fk_alliance_sub_plan` FOREIGN KEY (`plan_id`)
                    REFERENCES `" . self::TABLE_PLAN . "` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        Capsule::statement("
            CREATE TABLE IF NOT EXISTS `" . self::TABLE_SCHEDULED_PAYMENT . "` (
                `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `subscription_id`       INT UNSIGNED NOT NULL,
                `scheduled_payment_id`  VARCHAR(255) NULL,
                `payment_number`        INT UNSIGNED NULL,
                `status`                VARCHAR(20)  NOT NULL DEFAULT 'WAITING'
                    COMMENT 'WAITING|PROCESSING|SUCCESS|FAIL|RETRY|SKIPPED_PAUSED|INVALIDATED|NEEDS_REVIEW',
                `scheduled_date`        VARCHAR(40)  NULL COMMENT 'as sent by provider, with offset',
                `executed_at`           DATETIME     NULL,
                `operation_id`          VARCHAR(255) NULL,
                `coin_amount`           INT          NULL,
                `amount_mismatch`       TINYINT(1)   NOT NULL DEFAULT 0,
                `retry_count`           INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`            DATETIME     NOT NULL,
                `updated_at`            DATETIME     NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_operation_id` (`operation_id`),
                UNIQUE KEY `uniq_sub_scheduled` (`subscription_id`, `scheduled_payment_id`),
                CONSTRAINT `fk_alliance_sub_sched` FOREIGN KEY (`subscription_id`)
                    REFERENCES `" . self::TABLE_SUBSCRIPTION . "` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        Capsule::statement("
            CREATE TABLE IF NOT EXISTS `" . self::TABLE_VERSION_HISTORY . "` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plan_id`        INT UNSIGNED NOT NULL,
                `old_version_id` VARCHAR(255) NULL,
                `new_version_id` VARCHAR(255) NULL,
                `old_rules`      LONGTEXT     NOT NULL,
                `new_rules`      LONGTEXT     NOT NULL,
                `applies_from`   DATETIME     NULL,
                `reason`         TEXT         NULL,
                `created_by`     INT UNSIGNED NULL COMMENT 'tbladmins.id',
                `created_at`     DATETIME     NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_plan_id` (`plan_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        Capsule::statement("
            CREATE TABLE IF NOT EXISTS `" . self::TABLE_AUDIT_LOG . "` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `action`     VARCHAR(50)  NOT NULL,
                `plan_id`    INT UNSIGNED NULL,
                `product_id` INT UNSIGNED NULL,
                `user_id`    INT UNSIGNED NULL COMMENT 'tbladmins.id',
                `details`    LONGTEXT     NULL COMMENT 'JSON',
                `created_at` DATETIME     NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_plan_id` (`plan_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        Capsule::table('tblconfiguration')->updateOrInsert(
            ['setting' => self::VERSION_SETTING],
            ['value' => self::VERSION]
        );
    }
}
