<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Fiscalization;

use AlliancePay\AlliancePayHelper;
use DateTimeZone;
use Throwable;
use WHMCS\Database\Capsule;

/**
 * Class AdminBlock. Admin UI: invoice block and product form (rendering and saving).
 */
class AdminBlock
{
    public const ACTION_URL = '../modules/gateways/alliancepay/fiscalization_action.php';

    /**
     * @param int $invoiceId
     * @param array $settingsParams
     * @param string $token
     * @param string $flashMessage
     * @return string
     */
    public static function renderInvoiceBlock(int $invoiceId, array $settingsParams, string $token, string $flashMessage = ''): string
    {
        if (!(new FiscalizationSettings($settingsParams))->isEnabled()) {
            return '';
        }

        $operations = self::operations($invoiceId);
        if ($operations === []) {
            return '';
        }

        $h = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $html = '<div class="panel panel-default" style="margin-top:15px"><div class="panel-heading">'
                . '<strong>Фіскалізація AlliancePay</strong></div><div class="panel-body">';

        if ($flashMessage !== '') {
            $html .= '<div class="alert alert-info">' . $h($flashMessage) . '</div>';
        }

        $html .= '<table class="table table-condensed"><thead><tr><th>Операція</th><th>Тип</th><th>Статус</th><th>ФН</th>'
            . '<th>Чек</th><th>Дата</th><th>Помилка</th><th></th></tr></thead><tbody>';

        foreach ($operations as $op) {
            $record = $op['record'];
            $status = $record['status'] ?? '—';
            $receipt = !empty($record['receipt_url'])
                ? '<a href="' . $h($record['receipt_url']) . '" target="_blank" rel="noopener">Чек</a>'
                : '';
            $error = trim(($record['error_code'] ?? '') . ' ' . ($record['error_description'] ?? ''));

            $buttons = '';
            if (FiscalizationService::canFiscalize($record)) {
                $buttons .= self::actionForm($invoiceId, $op, 'fiscalize', 'Фіскалізувати', $token);
            }
            if (FiscalizationService::canRefresh($record)) {
                $buttons .= self::actionForm($invoiceId, $op, 'refresh', 'Оновити статус', $token);
            }

            $html .= '<tr><td>' . $h($op['operation_id']) . '</td><td>' . $h($op['type']) . '</td><td>' . $h($status)
                . '</td><td>' . $h($record['fiscal_number'] ?? '') . '</td><td>' . $receipt . '</td><td>'
                . $h(self::localDate($record['fiscalization_date_time'] ?? null)) . '</td><td>' . $h($error)
                . '</td><td>' . $buttons . '</td></tr>';
        }

        return $html . '</tbody></table></div></div>';
    }

    /**
     * @param int $invoiceId
     * @return array
     */
    public static function operations(int $invoiceId): array
    {
        $result = [];
        $rows = Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->where('gateway', AlliancePayHelper::GATEWAY_MODULE_NAME)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $operationId = (string)$row->transid;
            if ($operationId === '') {
                continue;
            }

            $isRefund = (float)$row->amountout > 0 && (float)$row->amountin <= 0;
            $original = null;
            if ($isRefund) {
                $original = Capsule::table('alliance_integration_order_refund')
                    ->where('operation_id', $operationId)
                    ->value('original_operation_id') ?: null;
            }

            $result[] = [
                'operation_id' => $operationId,
                'type' => $isRefund ? FiscalizationRepository::TYPE_REFUND : FiscalizationRepository::TYPE_PURCHASE,
                'coins' => (int)round(((float)($isRefund ? $row->amountout : $row->amountin)) * 100),
                'original_operation_id' => $original,
                'record' => FiscalizationRepository::findByOperationId($operationId),
            ];
        }

        return $result;
    }

    /**
     * @param int $productId
     * @return string
     */
    public static function renderProductForm(int $productId): string
    {
        $v = ProductFiscalRepository::get($productId);
        $h = static fn ($x): string => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');

        $field = static fn (string $name, string $label, string $hint, string $value, bool $textarea = false): string =>
            '<div class="form-group"><label>' . $label . '</label>'
            . ($textarea
                ? '<textarea class="form-control" rows="2" name="alliancepay_fiscal[' . $name . ']">' . $h($value) . '</textarea>'
                : '<input type="text" class="form-control" name="alliancepay_fiscal[' . $name . ']" value="' . $h($value) . '">')
            . '<p class="help-block">' . $hint . '</p></div>';

        $html = '<div id="alliancepay-fiscal-box" class="panel panel-default" style="margin:15px 0"><div class="panel-heading"><strong>Фіскалізація (AlliancePay)</strong></div><div class="panel-body">'
            . '<p class="text-muted">Порожнє поле — береться значення за замовчуванням з налаштувань шлюзу AlliancePay.</p>'
            . $field('taxCode', 'Код податкової групи (taxCode)', 'Ціле число, необов\'язково.', (string)$v['taxCode'])
            . $field('classificationCode', 'УКТЗЕД (classificationCode)', 'Лише цифри. Обов\'язковий, якщо taxCode = 2, 7 або 8 (у товарі або в налаштуваннях шлюзу).', (string)$v['classificationCode'])
            . $field('exciseLabels', 'Акцизні марки (exciseLabels)', 'Через кому або по одній в рядок.', (string)$v['exciseLabels'], true)
            . $field('measurementCode', 'Код одиниці виміру (measurementCode)', 'Наприклад 0001 (штука).', (string)$v['measurementCode'])
            . '</div></div>';

        $js = '<script>(function(){var b=document.getElementById("alliancepay-fiscal-box");if(!b)return;'
            . 'var f=document.querySelector(\'form[action*="configproducts.php?action=save"]\')||document.querySelector(\'form[action*="action=save"]\');'
            . 'if(f){f.appendChild(b);}else{b.style.display="none";}})();</script>';

        return $html . $js;
    }

    /**
     * @param int $productId
     * @param array $post
     * @return array
     */
    public static function saveProductFromPost(int $productId, array $post): array
    {
        if (!isset($post['alliancepay_fiscal']) || !is_array($post['alliancepay_fiscal'])) {
            return [];
        }

        $in = $post['alliancepay_fiscal'];
        $values = [
            'taxCode' => trim((string)($in['taxCode'] ?? '')),
            'classificationCode' => trim((string)($in['classificationCode'] ?? '')),
            'exciseLabels' => implode(', ', FiscalizationSettings::parseExciseLabels((string)($in['exciseLabels'] ?? ''))),
            'measurementCode' => trim((string)($in['measurementCode'] ?? '')),
        ];

        $errors = FiscalizationSettings::validateValues($values, 'Фіскалізація товару #' . $productId);
        if ($errors === []) {
            ProductFiscalRepository::save($productId, $values);
        }

        return $errors;
    }

    /**
     * @param int $invoiceId
     * @param array $op
     * @param string $action
     * @param string $label
     * @param string $token
     * @return string
     */
    private static function actionForm(int $invoiceId, array $op, string $action, string $label, string $token): string
    {
        $h = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        return '<form method="post" action="' . self::ACTION_URL . '" style="display:inline-block;margin-right:4px">'
            . '<input type="hidden" name="token" value="' . $h($token) . '">'
            . '<input type="hidden" name="invoiceid" value="' . $invoiceId . '">'
            . '<input type="hidden" name="operation_id" value="' . $h($op['operation_id']) . '">'
            . '<input type="hidden" name="action" value="' . $action . '">'
            . '<button type="submit" class="btn btn-xs btn-primary">' . $label . '</button></form>';
    }

    /**
     * @param string|null $utc
     * @return string Date in the WHMCS (store) time zone
     */
    private static function localDate(?string $utc): string
    {
        $date = FiscalizationRepository::fromUtc($utc);
        if ($date === null) {
            return '';
        }

        try {
            return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return $utc;
        }
    }
}
