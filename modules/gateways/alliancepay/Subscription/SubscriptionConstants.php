<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Subscription;

/**
 * Class SubscriptionConstants.
 */
final class SubscriptionConstants
{
    public const CONFIG_ENABLE_SUBSCRIPTIONS = 'enableSubscriptions';

    public const PLAN_STATUS_ACTIVE = 'ACTIVE';
    public const PLAN_STATUS_PAUSED = 'PAUSED';
    public const PLAN_STATUS_CANCELED = 'CANCELED';
    public const PLAN_STATUS_ERROR = 'ERROR';

    public const SYNC_SYNCED = 'SYNCED';
    public const SYNC_ERROR = 'ERROR';

    public const SUB_STATUS_PENDING = 'PENDING';
    public const SUB_STATUS_ACTIVE = 'ACTIVE';
    public const SUB_STATUS_CANCELED = 'CANCELED';
    public const SUB_STATUS_FAILED = 'FAILED';

    public const PAYMENT_WAITING = 'WAITING';
    public const PAYMENT_PROCESSING = 'PROCESSING';
    public const PAYMENT_SUCCESS = 'SUCCESS';
    public const PAYMENT_FAIL = 'FAIL';
    public const PAYMENT_RETRY = 'RETRY';
    public const PAYMENT_SKIPPED_PAUSED = 'SKIPPED_PAUSED';
    public const PAYMENT_INVALIDATED = 'INVALIDATED';
    public const PAYMENT_NEEDS_REVIEW = 'NEEDS_REVIEW';

    public const TEMPLATE_ACTIVE = 'active';
    public const TEMPLATE_ARCHIVED = 'archived';

    public const PLAN_CURRENCY = '980';

    public const REPRICE_MIN_LEAD_DAYS = 30;

    public const AUDIT_PLAN_CREATED = 'plan_created';
    public const AUDIT_PLAN_SYNC_FAILED = 'plan_sync_failed';
    public const AUDIT_PLAN_REPRICED = 'plan_repriced';
    public const AUDIT_PLAN_DEACTIVATED = 'plan_deactivated';
    public const AUDIT_PLAN_ARCHIVED = 'plan_archived';
    public const AUDIT_RUN_NOW = 'run_now';
}
