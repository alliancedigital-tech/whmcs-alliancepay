<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace AlliancePay\Fiscalization;

use AlliancePay\AlliancePayHelper;
use AlliancePay\Sdk\Exceptions\FiscalizationException;
use AlliancePay\Sdk\Exceptions\ValidateDataException;
use AlliancePay\Sdk\Payment\Dto\Fiscalization\FiscalizationRequestDTO;
use AlliancePay\Sdk\Payment\Dto\Fiscalization\FiscalizationResponseDTO;
use AlliancePay\Sdk\Payment\Dto\Order\OrderRequestDTO;
use AlliancePay\Sdk\Payment\Fiscalization\CreateFiscalization;
use AlliancePay\Sdk\Services\RequestIdentification\GenerateRequestIdentification;
use Exception;
use RuntimeException;
use Throwable;
use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

/**
 * Class FiscalizationService.
 */
class FiscalizationService
{
    public const CALLBACK_PATH = '/modules/gateways/callback/alliancepay_fiscalization.php';

    private FiscalizationSettings $settings;

    public function __construct(private array $gatewayParams, private ?CreateFiscalization $client = null)
    {
        $this->settings = new FiscalizationSettings($gatewayParams);
    }

    /**
     * @param array|null $record
     * @return bool
     */
    public static function canFiscalize(?array $record): bool
    {
        return $record === null || $record['status'] === FiscalizationRepository::STATUS_ERROR;
    }

    /**
     * @param array|null $record
     * @return bool
     */
    public static function canRefresh(?array $record): bool
    {
        return $record !== null
            && in_array($record['status'], [FiscalizationRepository::STATUS_PENDING, FiscalizationRepository::STATUS_FAIL], true);
    }

    /**
     * @param int $invoiceId
     * @param string $operationId
     * @param string $type
     * @param int $coins
     * @param string|null $originalOperationId
     * @return array
     */
    public function fiscalize(
        int $invoiceId,
        string $operationId,
        string $type,
        int $coins,
        ?string $originalOperationId = null
    ): array {
        try {
            if (!$this->settings->isEnabled()) {
                return $this->result(false, 'SKIPPED', 'Фіскалізацію вимкнено в налаштуваннях шлюзу.');
            }

            $record = FiscalizationRepository::findByOperationId($operationId);
            if (!self::canFiscalize($record)) {
                return $this->result(false, 'SKIPPED', 'Для цієї операції фіскалізацію вже створено (статус ' . $record['status'] . ').');
            }

            if (!$this->isUah($invoiceId)) {
                $this->log('Skipped: not UAH', ['invoice' => $invoiceId, 'operation' => $operationId], 'Фіскалізація доступна лише для UAH');

                return $this->result(false, 'SKIPPED', 'Фіскалізація доступна лише для замовлень у гривні (UAH).');
            }

            $errors = $this->settings->validateDefaults();
            if ($errors !== []) {
                return $this->failLocal($operationId, $invoiceId, $type, $originalOperationId, $record, null, implode(' ', $errors));
            }

            $resolver = new FiscalProductResolver($this->settings);
            $builder = new ProductsBuilder($resolver);

            try {
                if ($type === FiscalizationRepository::TYPE_REFUND) {
                    $products = $this->buildRefundProducts($builder, $operationId, $originalOperationId, $coins);
                } else {
                    $products = $builder->buildPurchase($invoiceId, $coins);
                }
            } catch (RuntimeException $e) {
                return $this->failLocal($operationId, $invoiceId, $type, $originalOperationId, $record, null, $e->getMessage());
            }

            $payload = [
                'merchantRequestId' => $record['merchant_request_id'] ?? GenerateRequestIdentification::generateRequestId(),
                'merchantId' => (string)($this->gatewayParams['merchantId'] ?? ''),
                'operationId' => $operationId,
                'receiptComment' => ($type === FiscalizationRepository::TYPE_REFUND ? 'REFUND_FOR_INVOICE_' : 'PAYMENT_FOR_INVOICE_') . $invoiceId,
                'notificationUrl' => $this->notificationUrl(),
                'products' => $products,
            ];

            return $this->send($invoiceId, $type, $originalOperationId, $record, $payload);
        } catch (Throwable $e) {
            $this->log('Fiscalize unexpected error', ['operation' => $operationId], $e->getMessage());

            return $this->result(false, FiscalizationRepository::STATUS_ERROR, 'Непередбачена помилка: ' . $e->getMessage());
        }
    }

    /**
     * @param string $operationId
     * @return array
     */
    public function refresh(string $operationId): array
    {
        try {
            $record = FiscalizationRepository::findByOperationId($operationId);
            if (!self::canRefresh($record)) {
                return $this->result(false, 'SKIPPED', 'Оновлення статусу для цього запису недоступне.');
            }

            $payload = json_decode((string)$record['request_payload'], true);
            if (!is_array($payload)) {
                return $this->result(false, 'SKIPPED', 'Збережений запит відсутній або пошкоджений.');
            }

            return $this->send(
                (int)$record['invoice_id'],
                (string)$record['operation_type'],
                $record['original_operation_id'] ?: null,
                $record,
                $payload
            );
        } catch (Throwable $e) {
            $this->log('Refresh unexpected error', ['operation' => $operationId], $e->getMessage());

            return $this->result(false, FiscalizationRepository::STATUS_ERROR, 'Непередбачена помилка: ' . $e->getMessage());
        }
    }

    /**
     * @param array $record
     * @param FiscalizationResponseDTO $dto
     * @param string $source
     * @param array $raw
     * @return bool
     * @throws Exception
     */
    public function applyUpdate(array $record, FiscalizationResponseDTO $dto, string $source, array $raw): bool
    {
        $payloadColumn = $source === 'callback' ? 'callback_payload' : 'response_payload';
        $json = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $storedStatus = (string)$record['status'];
        $storedModified = FiscalizationRepository::fromUtc($record['modification_date_time'] ?? null);
        $isFinalStored = in_array($storedStatus, [FiscalizationRepository::STATUS_SUCCESS, FiscalizationRepository::STATUS_FAIL], true);

        if ($isFinalStored && !$dto->isFinal()) {
            return false;
        }
        if ($storedStatus === FiscalizationRepository::STATUS_SUCCESS && $dto->getStatus() !== FiscalizationRepository::STATUS_SUCCESS) {
            return false;
        }
        if ($dto->getModificationDateTime() !== null && $storedModified !== null && !$dto->isNewerThan($storedModified)) {
            return false;
        }
        if ($dto->getModificationDateTime() !== null && $storedModified !== null
            && $dto->getModificationDateTime() == $storedModified
            && $dto->getStatus() === $storedStatus
        ) {
            return false;
        }

        FiscalizationRepository::upsert((string)$record['operation_id'], [
            'status' => $dto->getStatus(),
            'bucket_id' => $dto->getBucketId() ?? ($record['bucket_id'] ?? null),
            'fiscal_number' => $dto->getFiscalNumber(),
            'receipt_url' => $dto->getReceiptUrl(),
            'fiscalization_date_time' => FiscalizationRepository::toUtc($dto->getFiscalizationDateTime()),
            'fiscalization_modification_date_time' => FiscalizationRepository::toUtc($dto->getFiscalizationModificationDateTime()),
            'modification_date_time' => FiscalizationRepository::toUtc($dto->getModificationDateTime()),
            'error_code' => $dto->getErrorCode(),
            'error_description' => $dto->getErrorDescription(),
            $payloadColumn => $json,
        ]);

        if ($dto->isSuccess() && $storedStatus !== FiscalizationRepository::STATUS_SUCCESS) {
            $this->addInvoiceNote((int)$record['invoice_id'], 'Чек фіскалізовано, ФН ' . $dto->getFiscalNumber());
        } elseif ($dto->isFailed() && $storedStatus !== FiscalizationRepository::STATUS_FAIL) {
            $this->addInvoiceNote(
                (int)$record['invoice_id'],
                'Помилка фіскалізації: ' . $dto->getErrorCode() . ' ' . $dto->getErrorDescription()
            );
        }

        return true;
    }

    /**
     * @return string
     */
    public function notificationUrl(): string
    {
        return rtrim((string)Setting::getValue('SystemURL'), '/') . self::CALLBACK_PATH;
    }

    /**
     * @param int $invoiceId
     * @param string $type
     * @param string|null $originalOperationId
     * @param array|null $record
     * @param array $payload
     * @return array
     * @throws Exception
     */
    private function send(int $invoiceId, string $type, ?string $originalOperationId, ?array $record, array $payload): array
    {
        $operationId = (string)$payload['operationId'];

        try {
            $dto = FiscalizationRequestDTO::fromArray($payload);
        } catch (ValidateDataException $e) {
            return $this->failLocal($operationId, $invoiceId, $type, $originalOperationId, $record, $payload, $e->getMessage());
        }

        $payload = $dto->toArray();

        FiscalizationRepository::upsert($operationId, [
            'invoice_id' => $invoiceId,
            'operation_type' => $type,
            'original_operation_id' => $originalOperationId,
            'merchant_request_id' => $dto->getMerchantRequestId(),
            'receipt_comment' => $dto->getReceiptComment(),
            'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $record['status'] ?? FiscalizationRepository::STATUS_ERROR,
            'error_code' => $record['error_code'] ?? null,
            'error_description' => $record === null ? 'Запит ще не відправлено.' : ($record['error_description'] ?? null),
        ]);

        try {
            $authDto = AlliancePayHelper::getAuthDto($this->gatewayParams);
            $client = $this->client ?? new CreateFiscalization();
            $response = $client->createFiscalization($dto, $authDto);
        } catch (FiscalizationException $e) {
            $message = $this->describeException($e);
            $this->log('Fiscalization request failed', $payload, $message);

            return $this->markError($operationId, $e->getMsgCode(), $message);
        } catch (Throwable $e) {
            $this->log('Fiscalization request error', $payload, $e->getMessage());

            return $this->markError($operationId, null, 'Помилка відправки запиту: ' . $e->getMessage());
        }

        $this->log('Fiscalization response', $payload, json_encode($response->toArray(), JSON_UNESCAPED_UNICODE));

        $fresh = FiscalizationRepository::findByOperationId($operationId);
        if ($fresh !== null && $fresh['status'] === FiscalizationRepository::STATUS_ERROR) {
            FiscalizationRepository::upsert($operationId, ['status' => FiscalizationRepository::STATUS_PENDING, 'modification_date_time' => null]);
            $fresh['status'] = FiscalizationRepository::STATUS_PENDING;
            $fresh['modification_date_time'] = null;
        }
        $this->applyUpdate($fresh ?? [], $response, 'response', $response->toArray());

        $stored = FiscalizationRepository::findByOperationId($operationId);
        $status = $stored['status'] ?? $response->getStatus();

        return $this->result(true, $status, 'Статус фіскалізації: ' . $status);
    }

    /**
     * @param ProductsBuilder $builder
     * @param string $operationId
     * @param string|null $originalOperationId
     * @param int $coins
     * @return array[]
     */
    private function buildRefundProducts(ProductsBuilder $builder, string $operationId, ?string $originalOperationId, int $coins): array
    {
        $original = $originalOperationId ? FiscalizationRepository::findByOperationId($originalOperationId) : null;
        if ($original === null || $original['operation_type'] !== FiscalizationRepository::TYPE_PURCHASE
            || $original['status'] !== FiscalizationRepository::STATUS_SUCCESS
        ) {
            throw new RuntimeException('Для повернення спочатку потрібно успішно фіскалізувати оригінальну покупку.');
        }

        $payload = json_decode((string)$original['request_payload'], true);
        $products = is_array($payload) ? ($payload['products'] ?? []) : [];
        if ($products === []) {
            throw new RuntimeException('У фіскалізованій покупці відсутні збережені позиції.');
        }

        $previous = 0;
        foreach (FiscalizationRepository::findActiveRefundsOf((string)$originalOperationId) as $refund) {
            if ($refund['operation_id'] === $operationId) {
                continue;
            }
            $p = json_decode((string)$refund['request_payload'], true);
            $previous += ProductsBuilder::totalCoins(is_array($p) ? ($p['products'] ?? []) : []);
        }

        if ($previous + $coins > ProductsBuilder::totalCoins($products)) {
            throw new RuntimeException('Сума повернень перевищує суму фіскалізованої покупки.');
        }

        return $builder->buildRefund($products, $coins);
    }

    /**
     * @param string $operationId
     * @param string|null $msgCode
     * @param string $message
     * @return array{ok: bool, status: string, message: string}
     */
    private function markError(string $operationId, ?string $msgCode, string $message): array
    {
        FiscalizationRepository::upsert($operationId, [
            'status' => FiscalizationRepository::STATUS_ERROR,
            'error_code' => $msgCode,
            'error_description' => $message,
        ]);

        return $this->result(false, FiscalizationRepository::STATUS_ERROR, $message);
    }

    /**
     * @param string $operationId
     * @param int $invoiceId
     * @param string $type
     * @param string|null $originalOperationId
     * @param array|null $record
     * @param array|null $payload
     * @param string $message
     * @return array
     * @throws Exception
     */
    private function failLocal(
        string $operationId,
        int $invoiceId,
        string $type,
        ?string $originalOperationId,
        ?array $record,
        ?array $payload,
        string $message
    ): array {
        $this->log('Fiscalization validation error', ['operation' => $operationId], $message);

        FiscalizationRepository::upsert($operationId, [
            'invoice_id' => $invoiceId,
            'operation_type' => $type,
            'original_operation_id' => $originalOperationId,
            'merchant_request_id' => $record['merchant_request_id']
                ?? ($payload['merchantRequestId'] ?? GenerateRequestIdentification::generateRequestId()),
            'status' => FiscalizationRepository::STATUS_ERROR,
            'error_code' => 'local_validation',
            'error_description' => $message,
            'request_payload' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : ($record['request_payload'] ?? null),
        ]);

        return $this->result(false, FiscalizationRepository::STATUS_ERROR, $message);
    }

    /**
     * @param FiscalizationException $e
     * @return string
     */
    private function describeException(FiscalizationException $e): string
    {
        $attrs = $e->getPayload()['msgAttrs'] ?? null;
        $attrsText = $attrs !== null ? ' (' . json_encode($attrs, JSON_UNESCAPED_UNICODE) . ')' : '';

        switch ($e->getMsgCode()) {
            case 'missing_required_field':
                return 'Відсутнє обов\'язкове поле' . $attrsText;
            case 'invalid_format':
                return 'Невірний формат поля' . $attrsText;
            case 'payment_not_found':
                return 'Платіжну операцію не знайдено в Alliance Ecom.';
            case 'original_receipt_not_found':
                return 'Не знайдено оригінальний чек. Спочатку фіскалізуйте покупку.';
            default:
                return $e->getMessage();
        }
    }

    /**
     * @param int $invoiceId
     * @return bool
     */
    private function isUah(int $invoiceId): bool
    {
        $order = AlliancePayHelper::getCheckoutOrderByInvoice($invoiceId);
        if ($order && !empty($order->currency_code)) {
            return (int)$order->currency_code === OrderRequestDTO::CURRENCY_UAH;
        }

        $code = Capsule::table('tblinvoices')
            ->join('tblclients', 'tblclients.id', '=', 'tblinvoices.userid')
            ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
            ->where('tblinvoices.id', $invoiceId)
            ->value('tblcurrencies.code');

        return strtoupper((string)$code) === 'UAH';
    }

    /**
     * @param int $invoiceId
     * @param string $line
     * @return void
     */
    private function addInvoiceNote(int $invoiceId, string $line): void
    {
        try {
            $notes = (string)Capsule::table('tblinvoices')->where('id', $invoiceId)->value('notes');
            Capsule::table('tblinvoices')->where('id', $invoiceId)->update([
                'notes' => trim($notes . "\n[" . date('Y-m-d H:i') . '] ' . $line),
            ]);
        } catch (Throwable $e) {
            $this->log('Invoice note failed', ['invoice' => $invoiceId], $e->getMessage());
        }
    }

    /**
     * @param string $action
     * @param array $request
     * @param string $response
     * @return void
     */
    private function log(string $action, array $request, string $response): void
    {
        unset($request['merchantId']);
        logModuleCall(AlliancePayHelper::GATEWAY_MODULE_NAME, 'Fiscalization: ' . $action, $request, $response);
    }

    /**
     * @param bool $ok
     * @param string $status
     * @param string $message
     * @return array{ok: bool, status: string, message: string}
     */
    private function result(bool $ok, string $status, string $message): array
    {
        return ['ok' => $ok, 'status' => $status, 'message' => $message];
    }
}
