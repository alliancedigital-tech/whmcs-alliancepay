<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Fiscalization;

use WHMCS\Database\Capsule;

/**
 * Class FiscalizationSchema.
 */
class FiscalizationSchema
{
    public const VERSION_SETTING = 'alliancepay_fiscal_schema_version';

    public const VERSION = '1';

    public const TABLE_FISCALIZATION = 'alliance_fiscalization';

    public const TABLE_PRODUCT_FISCAL = 'alliance_product_fiscal';

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
            CREATE TABLE IF NOT EXISTS `" . self::TABLE_FISCALIZATION . "` (
                `id`                                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `invoice_id`                           INT          NOT NULL,
                `operation_id`                         VARCHAR(64)  NOT NULL COMMENT 'Ecom operationId',
                `original_operation_id`                VARCHAR(64)  NULL     COMMENT 'For REFUND: operationId of the original PURCHASE',
                `operation_type`                       VARCHAR(16)  NOT NULL COMMENT 'PURCHASE|REFUND',
                `merchant_request_id`                  VARCHAR(64)  NOT NULL,
                `bucket_id`                            VARCHAR(64)  NULL,
                `status`                               VARCHAR(16)  NOT NULL COMMENT 'PENDING|SUCCESS|FAIL|ERROR(local)',
                `receipt_comment`                      VARCHAR(255) NULL,
                `fiscal_number`                        VARCHAR(64)  NULL,
                `receipt_url`                          TEXT         NULL,
                `fiscalization_date_time`              DATETIME     NULL COMMENT 'UTC',
                `fiscalization_modification_date_time` DATETIME     NULL COMMENT 'UTC',
                `modification_date_time`               DATETIME     NULL COMMENT 'UTC, Ecom modificationDateTime',
                `error_code`                           VARCHAR(32)  NULL,
                `error_description`                    TEXT         NULL,
                `request_payload`                      LONGTEXT     NULL,
                `response_payload`                     LONGTEXT     NULL,
                `callback_payload`                     LONGTEXT     NULL,
                `created_at`                           DATETIME     NOT NULL,
                `updated_at`                           DATETIME     NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_operation_id` (`operation_id`),
                UNIQUE KEY `uniq_merchant_request_id` (`merchant_request_id`),
                KEY `idx_invoice_id` (`invoice_id`),
                KEY `idx_status` (`status`),
                KEY `idx_bucket_id` (`bucket_id`),
                KEY `idx_original_operation_id` (`original_operation_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Alliance Pay fiscalization'
        ");

        Capsule::statement("
            CREATE TABLE IF NOT EXISTS `" . self::TABLE_PRODUCT_FISCAL . "` (
                `product_id`          INT UNSIGNED NOT NULL,
                `tax_code`            VARCHAR(8)   NULL,
                `classification_code` VARCHAR(32)  NULL,
                `excise_labels`       TEXT         NULL,
                `measurement_code`    VARCHAR(16)  NULL,
                `updated_at`          DATETIME     NOT NULL,
                PRIMARY KEY (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Alliance Pay fiscal attributes of WHMCS products'
        ");

        Capsule::table('tblconfiguration')->updateOrInsert(
            ['setting' => self::VERSION_SETTING],
            ['value' => self::VERSION]
        );
    }
}
