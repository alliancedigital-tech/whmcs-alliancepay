<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/AlliancePayHelper.php';

use WHMCS\Authentication\CurrentUser;
use AlliancePay\Sdk\Payment\Order\CheckOrderData;
use alliancepay\AlliancePayHelper;

$currentUser = new CurrentUser();
if (!$currentUser->isAuthenticatedAdmin()) {
    header("HTTP/1.1 403 Forbidden");
    die('Доступ заборонено. Будь ласка, авторизуйтесь в адмін-панелі WHMCS.');
}

$hppOrderId = $_GET['hppOrderId'] ?? '';
$errorMsg = '';
$orderDataArray = [];
$amount = 0;

if (empty($hppOrderId)) {
    $errorMsg = 'Ідентифікатор замовлення (hppOrderId) не вказано.';
} else {
    $gatewayParams = getGatewayVariables('alliancepay');

    if (!$gatewayParams['type']) {
        $errorMsg = 'Платіжний модуль AlliancePay не активовано.';
    } else {
        $maxAttempts = 2;
        $attempt = 1;
        $authDto = null;

        while ($attempt <= $maxAttempts) {
            try {
                if (!$authDto) {
                    $gatewayParams['authenticationKey'] = html_entity_decode($gatewayParams['authenticationKey'], ENT_QUOTES, 'UTF-8');
                    $authDto = AlliancePayHelper::getAuthDto($gatewayParams);
                }

                $checkService = new CheckOrderData();
                $orderStatus = $checkService->checkOrderData($hppOrderId, $authDto);

                $orderDataArray = $orderStatus->toArray();
                $amount = isset($orderDataArray['coinAmount']) ? ($orderDataArray['coinAmount'] / 100) : 0;
                break;

            } catch (\Exception $e) {
                $exceptionMsg = $e->getMessage();
                $isAuthError = strpos($exceptionMsg, '401') !== false ||
                        strpos($exceptionMsg, 'b_used_token') !== false ||
                        strpos($exceptionMsg, 'b_auth_token_expired') !== false;

                if ($isAuthError && $attempt < $maxAttempts) {
                    $authDto = AlliancePayHelper::forceReauthorize($gatewayParams);
                    $attempt++;
                    continue;
                }

                $errorMsg = 'Помилка API при перевірці статусу: ' . $exceptionMsg;
                break;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Деталі замовлення AlliancePay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; padding: 40px 20px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { max-width: 800px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: none; border-radius: 8px; }
        .card-header { background-color: #fff; border-bottom: 2px solid #f0f2f5; padding: 20px; border-radius: 8px 8px 0 0 !important; }
        .op-card { border-left: 4px solid #0d6efd; background-color: #fff; margin-bottom: 15px; border-radius: 4px; }
        .op-status-success { border-left-color: #198754; }
        .op-status-error { border-left-color: #dc3545; }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="mb-0 text-dark"><i class="fas fa-search-dollar text-primary me-2"></i> Деталі замовлення AlliancePay</h4>
            <span class="badge bg-secondary">WHMCS Admin Interface</span>
        </div>
        <div class="card-body p-4">

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger mb-0"><i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($errorMsg) ?></div>
            <?php else: ?>

                <h5 class="mb-3 text-muted">Загальна інформація</h5>
                <table class="table table-bordered mb-4">
                    <tbody>
                        <tr>
                            <th scope="row" class="w-40 bg-light">HPP Order ID</th>
                            <td class="font-monospace"><?= htmlspecialchars($hppOrderId) ?></td>
                        </tr>
                        <tr>
                            <th scope="row" class="bg-light">Статус замовлення</th>
                            <td>
                                <?php
                                $status = $orderDataArray['orderStatus'] ?? 'UNKNOWN';
                                $badgeClass = 'bg-secondary';
                                if ($status === 'SUCCESS') $badgeClass = 'bg-success';
                                if ($status === 'PENDING') $badgeClass = 'bg-warning text-dark';
                                if ($status === 'REJECTED' || $status === 'FAIL') $badgeClass = 'bg-danger';
                                ?>
                                <span class="badge <?= $badgeClass ?> fs-6"><?= $status ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" class="bg-light">Сума</th>
                            <td class="fs-5 fw-bold"><?= number_format($amount, 2, '.', '') ?></td>
                        </tr>
                        <?php if (!empty($orderDataArray['statusUrl'])): ?>
                        <tr>
                            <th scope="row" class="bg-light">Status URL</th>
                            <td><a href="<?= htmlspecialchars($orderDataArray['statusUrl']) ?>" target="_blank" class="text-decoration-none small text-truncate d-block" style="max-width: 400px;"><?= htmlspecialchars($orderDataArray['statusUrl']) ?></a></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <hr class="my-4">

                <h5 class="mb-3 text-muted"><i class="fas fa-list-ul me-2"></i>Історія операцій (Operations)</h5>

                <?php if (!empty($orderDataArray['operations']) && is_array($orderDataArray['operations'])): ?>
                    <?php foreach ($orderDataArray['operations'] as $op): ?>
                        <?php
                        $isSuccess = ($op->getStatus() ?? '') === 'SUCCESS';
                        $opClass = $isSuccess ? 'op-status-success' : 'op-status-error';
                        ?>
                        <div class="card op-card <?= $opClass ?> shadow-sm">
                            <div class="card-body p-3">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <div class="small text-muted text-uppercase fw-bold">Тип операції</div>
                                        <div><?= htmlspecialchars($op->getType() ?? 'N/A') ?></div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="small text-muted text-uppercase fw-bold">Дата обробки</div>
                                        <div class="small"><?= htmlspecialchars($op->getProcessingDateTime()->format('d-m-Y H:i:s') ?? 'N/A') ?></div>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <span class="badge <?= $isSuccess ? 'bg-success' : 'bg-danger' ?>">
                                            <?= htmlspecialchars($op->getStatus() ?? 'UNKNOWN') ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="row mt-3 pt-2 border-top">
                                    <div class="col-md-7">
                                        <div class="small text-muted">ID операції: <span class="font-monospace"><?= htmlspecialchars($op->getOperationId() ?? 'N/A') ?></span></div>
                                    </div>
                                    <div class="col-md-5 text-end">
                                        <?php $receiptUrl = $op->getType() !== 'REFUND' ? $op->getReceiptUrl() : ''; ?>
                                        <?php if (!empty($receiptUrl)): ?>
                                            <a href="<?= htmlspecialchars($op->getReceiptUrl()) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-file-invoice"></i> Квитанція
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-center text-muted">Дані про операції відсутні.</p>
                <?php endif; ?>

            <?php endif; ?>

        </div>
        <div class="card-footer bg-white border-top-0 p-3 text-center">
            <button onclick="window.close();" class="btn btn-secondary px-4">Закрити вікно</button>
        </div>
    </div>
</div>

</body>
</html>