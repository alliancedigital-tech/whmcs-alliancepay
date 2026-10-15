<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePaySubscriptions;

use AlliancePay\AlliancePayHelper;
use AlliancePay\Subscription\AuditLog;
use AlliancePay\Subscription\PlanKey;
use AlliancePay\Subscription\PlanRepository;
use AlliancePay\Subscription\PlanService;
use AlliancePay\Subscription\RuleBuilder;
use AlliancePay\Subscription\ScheduledPaymentRepository;
use AlliancePay\Subscription\SubscriptionConstants;
use AlliancePay\Subscription\SubscriptionDiagnostics;
use AlliancePay\Subscription\SubscriptionRepository;
use AlliancePay\Subscription\VersionHistoryRepository;
use AlliancePay\Subscription\SubscriptionSchema;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule;

/**
 * Class AdminUi.
 */
class AdminUi
{
    private string $link;

    private array $gatewayParams = [];

    /**
     * @var string[]
     */
    private array $errors = [];

    private ?string $notice = null;

    private ?string $warning = null;

    public function __construct(private array $vars)
    {
        $this->link = (string)($vars['modulelink'] ?? '');
    }

    /**
     * @return void
     */
    public function dispatch(): void
    {
        SubscriptionSchema::ensure();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->handlePost((string)($_POST['do'] ?? ''));
        }

        $action = (string)($_REQUEST['action'] ?? 'plans');

        echo $this->nav($action);
        echo $this->flash();

        match ($action) {
            'new_plan' => $this->renderNewPlan(),
            'audit' => $this->renderAudit(),
            'plan' => $this->renderPlan((int)($_REQUEST['id'] ?? 0)),
            'reprice' => $this->renderReprice((int)($_REQUEST['plan_id'] ?? 0)),
            'subscriptions' => $this->renderSubscriptions(),
            'subscription' => $this->renderSubscription((int)($_REQUEST['id'] ?? 0)),
            default => $this->renderPlans(),
        };
    }

    /**
     * @param string $do
     * @return void
     */
    private function handlePost(string $do): void
    {
        switch ($do) {
            case 'create_plan':
                $result = PlanService::create($this->gatewayParams(), $_POST, $this->adminId());
                $this->errors = $result['errors'];
                $this->warning = $result['warning'];
                if ($result['ok']) {
                    $this->notice = 'План #' . $result['plan_id'] . ' створено.';
                    $_REQUEST['action'] = 'plans';
                } elseif ($result['warning'] !== null) {
                    $_REQUEST['action'] = 'plans';
                } else {
                    $_REQUEST['action'] = 'new_plan';
                }
                break;

            case 'retry_sync':
                $result = PlanService::retryInfiniteSchedule(
                    $this->gatewayParams(),
                    (int)($_POST['plan_id'] ?? 0),
                    $this->adminId()
                );
                $this->errors = $result['errors'];
                $this->notice = $result['ok'] ? 'Синхронізацію плану виконано.' : null;
                $_REQUEST['action'] = 'plans';
                break;

            case 'toggle_offer':
                PlanService::setOfferedToNewCustomers(
                    (int)($_POST['plan_id'] ?? 0),
                    (bool)($_POST['offered'] ?? false),
                    $this->adminId()
                );
                $this->notice = 'Статус пропозиції плану змінено (лише локально; в банку план не змінено).';
                $_REQUEST['action'] = 'plans';
                break;

            case 'archive_plan':
                if (!$this->requirePrivileged()) {
                    break;
                }
                $result = PlanService::archiveAtProvider($this->gatewayParams(), (int)($_POST['plan_id'] ?? 0), $this->adminId());
                $this->errors = $result['errors'];
                $this->notice = $result['ok'] ? 'План архівовано в банку.' : null;
                $_REQUEST['action'] = 'plans';
                break;

            case 'reprice_plan':
                if (!$this->requirePrivileged()) {
                    break;
                }
                $result = PlanService::reprice(
                    $this->gatewayParams(),
                    (int)($_POST['plan_id'] ?? 0),
                    (int)round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')) * 100),
                    (string)($_POST['applies_from'] ?? ''),
                    !empty($_POST['customers_notified']),
                    (string)($_POST['reason'] ?? ''),
                    $this->adminId()
                );
                $this->errors = $result['errors'];
                $this->warning = $result['warning'];
                if ($result['ok']) {
                    $this->notice = 'Нові правила плану збережено; діятимуть з ' . $this->e((string)($_POST['applies_from'] ?? '')) . '.';
                }
                $_REQUEST['action'] = ($result['ok'] || $result['warning']) ? 'plans' : 'reprice';
                break;

            case 'run_now':
                if (!$this->requirePrivileged()) {
                    break;
                }
                $result = SubscriptionDiagnostics::runNow($this->gatewayParams(), (int)($_POST['row_id'] ?? 0), $this->adminId());
                $result['ok'] ? $this->notice = $result['message'] : $this->errors[] = $result['message'];
                $_REQUEST['action'] = 'subscription';
                $_REQUEST['id'] = (int)($_POST['subscription_id'] ?? 0);
                break;
        }
    }

    /**
     * @return void
     */
    private function renderPlans(): void
    {
        $products = $this->productNames();
        $out = '<h3>Плани підписок</h3><table class="datatable" width="100%"><tr>'
            . '<th>ID</th><th>Товар</th><th>Цикл</th><th>Назва</th><th>Plan ID (банк)</th><th>Статус</th>'
            . '<th>Синхр.</th><th>Новим клієнтам</th><th>Активних підписок</th><th></th></tr>';

        $plans = PlanRepository::list();
        foreach ($plans as $plan) {
            $sync = $plan['sync_status'] === SubscriptionConstants::SYNC_SYNCED
                ? 'OK'
                : '<span class="textred" title="' . $this->e((string)$plan['sync_error']) . '">ERROR</span>';

            $action = $this->planActions($plan);
            if ($plan['sync_status'] === SubscriptionConstants::SYNC_ERROR) {
                $action .= '<form method="post" action="' . $this->e($this->link) . '">' . generate_token('form')
                    . '<input type="hidden" name="do" value="retry_sync">'
                    . '<input type="hidden" name="plan_id" value="' . $plan['id'] . '">'
                    . '<button class="btn btn-warning btn-xs" type="submit">Повторити синхронізацію</button></form>';
            }

            $out .= '<tr><td>' . $plan['id'] . '</td>'
                . '<td>' . $this->e($products[$plan['product_id']] ?? ('#' . $plan['product_id'])) . '</td>'
                . '<td>' . $this->e($plan['billing_cycle']) . '</td>'
                . '<td>' . $this->e($plan['name']) . '</td>'
                . '<td><code>' . $this->e($plan['provider_plan_id']) . '</code></td>'
                . '<td>' . $this->e($plan['status']) . '</td>'
                . '<td>' . $sync . '</td>'
                . '<td>' . ($plan['is_active'] ? 'так' : 'ні')
                . ($plan['archive_candidate'] ? ' <span class="label label-warning">кандидат на архівацію</span>' : '') . '</td>'
                . '<td>' . $plan['active_subscriptions_count'] . '</td>'
                . '<td>' . $action . '</td></tr>';
        }
        if ($plans === []) {
            $out .= '<tr><td colspan="10" align="center">Планів ще немає.</td></tr>';
        }

        echo $out . '</table>';
    }

    /**
     * @return void
     */
    private function renderNewPlan(): void
    {
        $productId = (int)($_REQUEST['product_id'] ?? 0);
        $cycle = (string)($_REQUEST['billing_cycle'] ?? '');
        $isPostBack = ($_POST['do'] ?? '') === 'create_plan';

        $out = '<h3>Новий план</h3>'
            . '<form method="get" action="' . $this->e($this->link) . '" class="form-inline">'
            . '<input type="hidden" name="module" value="alliancepay_subscriptions">'
            . '<input type="hidden" name="action" value="new_plan">'
            . '<p>Крок 1. Оберіть товар і цикл — розклад та сума плану визначаються ними автоматично '
            . '(для іншої періодичності створіть окремий план з відповідним циклом).</p>'
            . $this->select('product_id', $this->productNames(), $productId, '— товар —') . ' '
            . $this->select('billing_cycle', array_combine(RuleBuilder::supportedCycles(), RuleBuilder::supportedCycles()), $cycle, '— цикл —') . ' '
            . '<button class="btn btn-default" type="submit">Далі</button></form><hr>';

        if ($productId && !RuleBuilder::isSupportedCycle($cycle)) {
            $this->errors[] = 'Оберіть цикл (billing cycle).';
        }
        if (!$productId || !RuleBuilder::isSupportedCycle($cycle)) {
            echo $this->flash() . $out;
            return;
        }

        $price = RuleBuilder::productPriceCoins($productId, $cycle);
        if ($price === null || $price <= 0) {
            $this->errors[] = 'Для цього товару й циклу не знайдено ціну в UAH (перевірте валюту UAH і ціни продукту).';
            echo $this->flash() . $out;
            return;
        }

        $rules = RuleBuilder::ruleForCycle($cycle, $price, RuleBuilder::productSetupFeeCoins($productId, $cycle));
        $defaultName = trim(($this->productNames()[$productId] ?? '') . ' — ' . $cycle);
        $name = $isPostBack ? (string)($_POST['name'] ?? '') : $defaultName;
        $description = $isPostBack ? (string)($_POST['description'] ?? '') : $defaultName;
        $category = $isPostBack
            ? (string)($_POST['category_indicator'] ?? '')
            : (string)($this->vars['defaultCategoryIndicator'] ?? '');

        $out .= '<form method="post" action="' . $this->e($this->link) . '&action=new_plan">' . generate_token('form')
            . '<input type="hidden" name="do" value="create_plan">'
            . '<input type="hidden" name="product_id" value="' . $productId . '">'
            . '<input type="hidden" name="billing_cycle" value="' . $this->e($cycle) . '">'
            . '<p>Крок 2. Перевірте параметри та створіть план.</p>'
            . '<div class="infobox">Розклад: ' . $this->describeRules($rules) . '<br>Суми беруться з ціни продукту та setup fee '
            . '(Setup → Products/Services → Pricing, UAH): setup fee входить лише в перший платіж. '
            . 'Щоб змінити суми — змініть ціни продукту й створіть новий план.</div>'
            . '<table class="form" width="100%"><tr><td class="fieldlabel">Назва</td><td class="fieldarea">'
            . '<input class="form-control" name="name" maxlength="255" value="' . $this->e($name) . '"></td></tr>'
            . '<tr><td class="fieldlabel">Опис</td><td class="fieldarea">'
            . '<textarea class="form-control" name="description" maxlength="1000">' . $this->e($description) . '</textarea></td></tr>'
            . '<tr><td class="fieldlabel">Category indicator</td><td class="fieldarea">'
            . '<input class="form-control" name="category_indicator" maxlength="50" value="' . $this->e($category) . '"></td></tr>'
            . '</table>'
            . '<p><button class="btn btn-primary" type="submit">Створити план у банку</button></p></form>';

        echo $out;
    }

    /**
     * @param array $rule
     * @return string
     */
    private function describeRules(array $rules): string
    {
        if (count($rules) === 2) {
            return 'перший платіж <strong>' . number_format($rules[0]['coinAmount'] / 100, 2, '.', ' ') . ' UAH</strong> (з setup fee), '
                . 'далі ' . $this->describeRule($rules[1]);
        }

        return $this->describeRule($rules[0]);
    }

    /**
     * @param array $rule
     * @return string
     */
    private function describeRule(array $rule): string
    {
        return 'кожні <strong>' . (int)$rule['intervalValue'] . ' міс.</strong>, <strong>'
            . number_format(((int)$rule['coinAmount']) / 100, 2, '.', ' ') . ' UAH</strong>, безстроково';
    }

    /**
     * @param array $plan
     * @return string
     */
    private function planActions(array $plan): string
    {
        $html = '<a class="btn btn-info btn-xs" href="' . $this->e($this->link) . '&action=plan&id=' . $plan['id'] . '">Дані з банку</a> ';
        $post = static fn (string $do, int $id, string $extra = '') => '<input type="hidden" name="do" value="' . $do . '">'
            . '<input type="hidden" name="plan_id" value="' . $id . '">' . $extra;
        $form = fn (string $inner) => '<form method="post" action="' . $this->e($this->link) . '&action=plans" style="display:inline">'
            . generate_token('form') . $inner . '</form> ';

        if ($plan['status'] === SubscriptionConstants::PLAN_STATUS_ACTIVE && $plan['sync_status'] === SubscriptionConstants::SYNC_SYNCED) {
            $html .= '<a class="btn btn-default btn-xs" href="' . $this->e($this->link) . '&action=reprice&plan_id=' . $plan['id'] . '">Змінити ціну</a> ';
            $html .= $form($post(
                'toggle_offer',
                $plan['id'],
                '<input type="hidden" name="offered" value="' . ($plan['is_active'] ? 0 : 1) . '">'
                . '<button class="btn btn-default btn-xs" type="submit">'
                . ($plan['is_active'] ? 'Не пропонувати новим' : 'Пропонувати новим') . '</button>'
            ));
        }
        if ($plan['archive_candidate']) {
            $html .= $form($post(
                'archive_plan',
                $plan['id'],
                '<button class="btn btn-danger btn-xs" type="submit" onclick="return confirm(\'Архівувати план в банку?\')">Архівувати в банку</button>'
            ));
        }

        return $html;
    }

    /**
     * @param int $id
     * @return void
     */
    private function renderPlan(int $id): void
    {
        $plan = PlanRepository::find($id);
        if (!$plan) {
            echo '<div class="errorbox">План не знайдено.</div>';
            return;
        }

        $out = '<h3>План #' . $plan['id'] . ' — ' . $this->e($plan['name']) . '</h3>'
            . '<h4>Локально</h4><table class="datatable" width="100%">'
            . '<tr><th>Plan ID (банк)</th><td><code>' . $this->e($plan['provider_plan_id']) . '</code></td>'
            . '<th>Версія</th><td>' . $this->e((string)$plan['version_id']) . '</td></tr>'
            . '<tr><th>Товар / цикл</th><td>#' . $plan['product_id'] . ' / ' . $this->e($plan['billing_cycle']) . '</td>'
            . '<th>Статус / синхр.</th><td>' . $this->e($plan['status']) . ' / ' . $this->e($plan['sync_status']) . '</td></tr>'
            . '<tr><th>Новим клієнтам</th><td>' . ($plan['is_active'] ? 'так' : 'ні') . '</td>'
            . '<th>Активних підписок (локально)</th><td>' . $plan['active_subscriptions_count'] . '</td></tr>'
            . '<tr><th>Розклад</th><td colspan="3">' . ($plan['rules'] ? $this->describeRules($plan['rules']) : '—') . '</td></tr>'
            . '</table>';

        $remote = SubscriptionDiagnostics::fetchPlan($this->gatewayParams(), (string)$plan['provider_plan_id']);
        $out .= '<h4>В банку (свіжий запит)</h4>';

        if ($remote['error'] !== null) {
            echo $out . '<div class="infobox">Не вдалося отримати дані плану з банку: ' . $this->e($remote['error']) . '</div>';
            return;
        }

        $d = $remote['details'];
        $summary = $d['plan'] ?? [];
        $stats = $d['subscriptionStatistics'] ?? [];

        $out .= '<table class="datatable" width="100%">'
            . '<tr><th>Назва</th><td>' . $this->e((string)($summary['name'] ?? '')) . '</td>'
            . '<th>Статус</th><td>' . $this->e((string)($summary['status'] ?? '')) . '</td></tr>'
            . '<tr><th>Category indicator</th><td>' . $this->e((string)($summary['categorySubcategoryIndicator'] ?? '')) . '</td>'
            . '<th>Створено / оновлено</th><td>' . $this->e((string)($summary['createdAt'] ?? '')) . ' / ' . $this->e((string)($summary['updatedAt'] ?? '')) . '</td></tr>'
            . '<tr><th>Опис</th><td colspan="3">' . $this->e((string)($summary['description'] ?? '')) . '</td></tr>'
            . '<tr><th>Підписки (банк)</th><td colspan="3">pending: <strong>' . (int)($stats['pending'] ?? 0) . '</strong>, success: <strong>'
            . (int)($stats['success'] ?? 0) . '</strong>, fail: <strong>' . (int)($stats['fail'] ?? 0) . '</strong>, canceled: <strong>'
            . (int)($stats['canceled'] ?? 0) . '</strong></td></tr></table>';

        $out .= '<h4>Версії</h4><table class="datatable" width="100%"><tr><th>Версія</th><th>Діє з</th><th>Правила</th></tr>';
        $localVersionRules = null;
        foreach ((array)($d['versions'] ?? []) as $version) {
            $rules = (array)($version['rules'] ?? []);
            $isLocal = (string)($version['versionId'] ?? '') === (string)$plan['version_id'];
            if ($isLocal) {
                $localVersionRules = $rules;
            }
            $lines = [];
            foreach ($rules as $r) {
                $lines[] = 'платежі ' . (int)($r['paymentNumberFrom'] ?? 0) . '–' . (int)($r['paymentNumberTo'] ?? 0)
                    . ': кожні ' . (int)($r['intervalValue'] ?? 0) . ' ' . $this->e((string)($r['intervalUnit'] ?? ''))
                    . ', ' . number_format(((int)($r['coinAmount'] ?? 0)) / 100, 2, '.', ' ') . ' ' . ($this->e((string)($r['currency'] ?? '')) === '980' ? 'UAH' : $this->e((string)($r['currency'] ?? '')));
            }
            $out .= '<tr><td>' . $this->e((string)($version['versionId'] ?? '')) . ($isLocal ? ' <span class="label label-info">локальна</span>' : '')
                . '</td><td>' . $this->e((string)($version['appliesDateTimeFrom'] ?? '—')) . '</td><td>' . implode('<br>', $lines) . '</td></tr>';
        }
        $out .= '</table>';

        if ($localVersionRules === null) {
            $out .= '<div class="infobox">Локальної версії ' . $this->e((string)$plan['version_id']) . ' немає серед версій банку.</div>';
        } else {
            try {
                $same = PlanKey::normalizeRules($localVersionRules) === PlanKey::normalizeRules($plan['rules']);
            } catch (\Throwable $e) {
                $same = false;
            }
            $out .= $same
                ? '<div class="successbox">Правила локальної версії збігаються з банком.</div>'
                : '<div class="errorbox">Правила локальної версії НЕ збігаються з банком — перевірте план.</div>';
        }
        if ((int)($stats['pending'] ?? 0) + (int)($stats['success'] ?? 0) !== (int)$plan['active_subscriptions_count']) {
            $out .= '<div class="infobox">Лічильник активних підписок у банку (pending + success) відрізняється від локального ('
                . $plan['active_subscriptions_count'] . '). Це довідкова інформація: банк рахує й підписки, оформлені поза WHMCS.</div>';
        }

        echo $out;
    }

    /**
     * @param int $planId
     * @return void
     */
    private function renderReprice(int $planId): void
    {
        $plan = PlanRepository::find($planId);
        if (!$plan) {
            echo '<div class="errorbox">План не знайдено.</div>';
            return;
        }

        $isPostBack = ($_POST['do'] ?? '') === 'reprice_plan';
        $currentRule = $plan['rules'] ? end($plan['rules']) : null;
        $amount = $isPostBack
            ? (string)($_POST['amount'] ?? '')
            : number_format(((int)($currentRule['coinAmount'] ?? 0)) / 100, 2, '.', '');
        $minDate = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Kyiv')))
            ->modify('+' . SubscriptionConstants::REPRICE_MIN_LEAD_DAYS . ' days')->format('Y-m-d');

        $out = '<h3>Зміна ціни плану #' . $plan['id'] . ' — ' . $this->e($plan['name']) . '</h3>'
            . '<div class="infobox">Це зміна умов <strong>для всіх активних підписників плану</strong>: <strong>'
            . $plan['active_subscriptions_count'] . '</strong> підписок перейдуть на нові правила з вибраної дати. '
            . 'Якщо зміна має стосуватись лише нових клієнтів — створіть новий план і натисніть «Не пропонувати новим» на старому.</div>';

        if (!$this->isPrivileged()) {
            echo $out . '<div class="errorbox">Недостатньо прав: зміна ціни доступна лише ролям із підвищеним доступом.</div>';
            return;
        }

        $out .= '<form method="post" action="' . $this->e($this->link) . '&action=reprice&plan_id=' . $plan['id'] . '">' . generate_token('form')
            . '<input type="hidden" name="do" value="reprice_plan"><input type="hidden" name="plan_id" value="' . $plan['id'] . '">'
            . ($plan['rules'] ? '<p>Поточний розклад: ' . $this->describeRules($plan['rules']) . '.</p>' : '')
            . (RuleBuilder::validateForCycle($plan['rules'], (string)$plan['billing_cycle']) !== []
                ? '<div class="infobox">План має нестандартні правила — нова версія замінить їх розкладом за циклом '
                    . $this->e($plan['billing_cycle']) . '.</div>' : '')
            . '<table class="form" width="100%">'
            . '<tr><td class="fieldlabel">Нова регулярна сума, UAH</td><td class="fieldarea">'
            . '<input class="form-control" type="number" step="0.01" min="0.01" name="amount" value="' . $this->e($amount) . '" style="width:auto"></td></tr>'
            . '<tr><td class="fieldlabel">Діє з (не раніше ' . $minDate . ')</td><td class="fieldarea">'
            . '<input class="form-control" type="date" name="applies_from" min="' . $minDate . '" value="'
            . $this->e((string)($_POST['applies_from'] ?? $minDate)) . '" style="width:auto"></td></tr>'
            . '<tr><td class="fieldlabel">Причина</td><td class="fieldarea">'
            . '<input class="form-control" name="reason" value="' . $this->e((string)($_POST['reason'] ?? '')) . '"></td></tr>'
            . '<tr><td class="fieldlabel"></td><td class="fieldarea"><label><input type="checkbox" name="customers_notified" value="1"'
            . (!empty($_POST['customers_notified']) ? ' checked' : '')
            . '> Підтверджую: клієнтів плану повідомлено (або буде повідомлено) про зміну ціни</label></td></tr></table>'
            . '<p><button class="btn btn-danger" type="submit">Застосувати нові правила</button></p></form>';

        $history = VersionHistoryRepository::listByPlan($planId);
        if ($history) {
            $out .= '<h4>Історія версій</h4><table class="datatable" width="100%"><tr><th>Дата</th><th>Діє з</th><th>Версії</th>'
                . '<th>Причина</th><th>Адмін</th><th>Старі → нові правила</th></tr>';
            foreach ($history as $h) {
                $out .= '<tr><td>' . $this->e($h['created_at']) . '</td><td>' . $this->e((string)$h['applies_from']) . '</td><td>'
                    . $this->e((string)$h['old_version_id']) . ' → ' . $this->e((string)$h['new_version_id']) . '</td><td>'
                    . $this->e((string)$h['reason']) . '</td><td>' . ($h['created_by'] ?? '') . '</td><td><code>'
                    . $this->e((string)json_encode($h['old_rules'])) . '<br>→ ' . $this->e((string)json_encode($h['new_rules'])) . '</code></td></tr>';
            }
            $out .= '</table>';
        }

        echo $out;
    }

    /**
     * @return void
     */
    private function renderSubscriptions(): void
    {
        $plans = [];
        foreach (PlanRepository::list() as $p) {
            $plans[$p['id']] = $p['name'];
        }

        $out = '<h3>Підписки</h3><table class="datatable" width="100%"><tr><th>ID</th><th>Сервіс</th><th>Клієнт</th><th>План</th>'
            . '<th>Статус</th><th>Order ID (банк)</th><th>Створено</th><th></th></tr>';
        foreach (SubscriptionRepository::list() as $sub) {
            $out .= '<tr><td>' . $sub['id'] . '</td>'
                . '<td><a href="clientsservices.php?userid=' . $sub['client_id'] . '&id=' . $sub['service_id'] . '">#' . $sub['service_id'] . '</a></td>'
                . '<td><a href="clientssummary.php?userid=' . $sub['client_id'] . '">#' . $sub['client_id'] . '</a></td>'
                . '<td>' . $this->e($plans[$sub['plan_id']] ?? ('#' . $sub['plan_id'])) . '</td>'
                . '<td>' . $this->e($sub['status']) . '</td><td><code>' . $this->e((string)$sub['provider_order_id']) . '</code></td>'
                . '<td>' . $this->e($sub['created_at']) . '</td>'
                . '<td><a href="' . $this->e($this->link) . '&action=subscription&id=' . $sub['id'] . '">Діагностика</a></td></tr>';
        }

        echo $out . '</table>';
    }

    /**
     * @param int $id
     * @return void
     */
    private function renderSubscription(int $id): void
    {
        $sub = SubscriptionRepository::find($id);
        if (!$sub) {
            echo '<div class="errorbox">Підписку не знайдено.</div>';
            return;
        }

        $out = '<h3>Підписка #' . $sub['id'] . '</h3><p>Статус: <strong>' . $this->e($sub['status']) . '</strong>, сервіс #'
            . $sub['service_id'] . ', рахунок #' . $sub['invoice_id'] . ', hppOrderId: <code>' . $this->e((string)$sub['provider_order_id']) . '</code></p>';

        $remote = $sub['provider_order_id']
            ? SubscriptionDiagnostics::fetchOrder($this->gatewayParams(), (string)$sub['provider_order_id'])
            : ['order' => null, 'operations' => [], 'error' => 'Підписка ще не має hppOrderId.'];

        $out .= '<h4>Операції в банку (свіжий запит)</h4>';
        if ($remote['error'] !== null) {
            $out .= '<div class="infobox">Не вдалося отримати дані з банку: ' . $this->e($remote['error']) . '</div>';
        } else {
            $out .= '<p>Статус замовлення: <strong>' . $this->e((string)$remote['order']['status']) . '</strong></p>'
                . '<table class="datatable" width="100%"><tr><th>Operation ID</th><th>Тип</th><th>Статус</th><th>Сума</th><th>Чек</th></tr>';
            foreach ($remote['operations'] as $op) {
                $receipt = !empty($op['receiptUrl']) ? '<a target="_blank" rel="noopener" href="' . $this->e((string)$op['receiptUrl']) . '">чек</a>' : '';
                $out .= '<tr><td><code>' . $this->e((string)($op['operationId'] ?? '')) . '</code></td><td>' . $this->e((string)($op['type'] ?? ''))
                    . '</td><td>' . $this->e((string)($op['status'] ?? '')) . '</td><td>'
                    . number_format(((int)($op['coinAmount'] ?? 0)) / 100, 2, '.', ' ') . '</td><td>' . $receipt . '</td></tr>';
            }
            if ($remote['operations'] === []) {
                $out .= '<tr><td colspan="5" align="center">Операцій немає.</td></tr>';
            }
            $out .= '</table>';
        }

        $out .= '<h4>Заплановані платежі (локальний журнал)</h4><table class="datatable" width="100%"><tr><th>#</th><th>scheduledPaymentId</th>'
            . '<th>Дата</th><th>Статус</th><th>Сума</th><th>Операція</th><th>Спроб</th><th></th></tr>';
        foreach (ScheduledPaymentRepository::listBySubscription($id) as $row) {
            $run = '';
            if ($this->isPrivileged() && $sub['status'] === SubscriptionConstants::SUB_STATUS_ACTIVE
                && $row['scheduled_payment_id'] && $row['status'] !== SubscriptionConstants::PAYMENT_SUCCESS) {
                $run = '<form method="post" action="' . $this->e($this->link) . '&action=subscription&id=' . $id . '" style="display:inline">'
                    . generate_token('form') . '<input type="hidden" name="do" value="run_now">'
                    . '<input type="hidden" name="row_id" value="' . $row['id'] . '"><input type="hidden" name="subscription_id" value="' . $id . '">'
                    . '<button class="btn btn-warning btn-xs" type="submit" onclick="return confirm(\'Ініціювати списання зараз?\')">Run Now</button></form>';
            }
            $out .= '<tr><td>' . ($row['payment_number'] ?? '') . '</td><td><code>' . $this->e((string)$row['scheduled_payment_id']) . '</code></td><td>'
                . $this->e((string)$row['scheduled_date']) . '</td><td>' . $this->e($row['status'])
                . ($row['amount_mismatch'] ? ' <span class="label label-warning">сума ≠ очікуваній</span>' : '') . '</td><td>'
                . ($row['coin_amount'] !== null ? number_format($row['coin_amount'] / 100, 2, '.', ' ') : '') . '</td><td><code>'
                . $this->e((string)$row['operation_id']) . '</code></td><td>' . $row['retry_count'] . '</td><td>' . $run . '</td></tr>';
        }

        echo $out . '</table>';
    }

    /**
     * @return void
     */
    private function renderAudit(): void
    {
        $out = '<h3>Журнал аудиту</h3><table class="datatable" width="100%"><tr><th>Дата</th><th>Дія</th><th>План</th><th>Товар</th><th>Адмін</th><th>Деталі</th></tr>';
        foreach (AuditLog::recent() as $row) {
            $out .= '<tr><td>' . $this->e($row['created_at']) . '</td><td>' . $this->e($row['action']) . '</td><td>'
                . ($row['plan_id'] ?? '') . '</td><td>' . ($row['product_id'] ?? '') . '</td><td>' . ($row['user_id'] ?? '')
                . '</td><td><code>' . $this->e((string)json_encode($row['details'], JSON_UNESCAPED_UNICODE)) . '</code></td></tr>';
        }

        echo $out . '</table>';
    }

    private function isPrivileged(): bool
    {
        $allowed = array_filter(array_map('trim', explode(',', (string)($this->vars['privilegedRoleIds'] ?: '1'))));
        $admin = (new CurrentUser())->admin();

        return $admin !== null && in_array((string)($admin->roleid ?? $admin->roleId ?? ""), $allowed, true);
    }

    /**
     * @return bool
     */
    private function requirePrivileged(): bool
    {
        if ($this->isPrivileged()) {
            return true;
        }
        $this->errors[] = 'Недостатньо прав для цієї дії.';

        return false;
    }

    /**
     * @return array
     */
    private function gatewayParams(): array
    {
        if ($this->gatewayParams === []) {
            require_once ROOTDIR . '/includes/gatewayfunctions.php';
            $this->gatewayParams = getGatewayVariables(AlliancePayHelper::GATEWAY_MODULE_NAME);
        }

        return $this->gatewayParams;
    }

    /**
     * @return int|null
     */
    private function adminId(): ?int
    {
        $admin = (new CurrentUser())->admin();

        return $admin ? (int)$admin->id : (isset($_SESSION['adminid']) ? (int)$_SESSION['adminid'] : null);
    }

    /**
     * @return array<int,
     */
    private function productNames(): array
    {
        $names = [];
        foreach (Capsule::table('tblproducts')->orderBy('name')->get(['id', 'name']) as $p) {
            $names[(int)$p->id] = (string)$p->name;
        }

        return $names;
    }

    /**
     * @param string $name
     * @param array $options
     * @param int|string $selected
     * @param string|null $empty
     * @return string
     */
    private function select(string $name, array $options, int|string $selected, ?string $empty = null): string
    {
        $html = '<select class="form-control" name="' . $this->e($name) . '" style="width:auto;display:inline-block">';
        if ($empty !== null) {
            $html .= '<option value="">' . $this->e($empty) . '</option>';
        }
        foreach ($options as $value => $label) {
            $html .= '<option value="' . $this->e((string)$value) . '"'
                . ((string)$value === (string)$selected && $selected !== 0 ? ' selected' : '') . '>'
                . $this->e((string)$label) . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * @param string $action
     * @return string
     */
    private function nav(string $action): string
    {
        $items = [
            'plans' => 'Плани',
            'new_plan' => 'Новий план',
            'subscriptions' => 'Підписки',
            'audit' => 'Аудит',
        ];
        $html = '<ul class="nav nav-tabs admin-tabs" style="margin-bottom:15px">';
        foreach ($items as $key => $label) {
            $active = $action === $key
                || ($key === 'subscriptions' && $action === 'subscription')
                || ($key === 'plans' && in_array($action, ['reprice', 'plan'], true));
            $html .= '<li' . ($active ? ' class="active"' : '') . '><a href="' . $this->e($this->link) . '&action=' . $key . '">' . $label . '</a></li>';
        }

        return $html . '</ul>';
    }

    /**
     * @return string
     */
    private function flash(): string
    {
        $html = '';
        foreach ($this->errors as $error) {
            $html .= '<div class="errorbox"><strong>' . $this->e($error) . '</strong></div>';
        }
        if ($this->warning) {
            $html .= '<div class="infobox"><strong>' . $this->e($this->warning) . '</strong></div>';
        }
        if ($this->notice) {
            $html .= '<div class="successbox"><strong>' . $this->e($this->notice) . '</strong></div>';
        }
        $this->errors = [];
        $this->warning = null;
        $this->notice = null;

        return $html;
    }

    /**
     * @param string $value
     * @return string
     */
    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
