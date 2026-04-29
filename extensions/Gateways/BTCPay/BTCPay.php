<?php

namespace Paymenter\Extensions\Gateways\BTCPay;

use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class BTCPay extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php';
    }

    /**
     * Get all the configuration for the extension
     *
     * @param  array  $values
     * @return array
     */
    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'instance_url',
                'label' => 'Instance URL',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'store_id',
                'label' => 'Store ID',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'api_key',
                'label' => 'API Key',
                'type' => 'password',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'webhook_secret',
                'label' => 'Webhook Secret',
                'type' => 'password',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'order_prefix',
                'label' => 'Order Prefix',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    public function getEndpoint()
    {
        $normalized = $this->normalizeInstanceUrl($this->config('instance_url'));
        $storeId = trim((string) $this->config('store_id'));
        if ($normalized === null || $storeId === '') {
            return '';
        }

        return $normalized . '/api/v1/stores/' . rawurlencode($storeId) . '/invoices';
    }

    /**
     * Return a view or a url to redirect to
     *
     * @param  Invoice  $invoice
     * @param  float  $total
     * @return string|\Illuminate\Http\RedirectResponse
     */
    public function pay(Invoice $invoice, $total)
    {
        if ($redirect = $this->validatePayPrerequisites($invoice, $total)) {
            return $redirect;
        }

        $endpoint = $this->getEndpoint();
        $orderId = $this->config('order_prefix') . $invoice->id;
        $user = $invoice->user;
        $userName = $user->name;
        $userEmail = $user->email;
        $invoiceRoute = route('invoices.show', ['invoice' => $invoice->id]);
        $payload = [
            'metadata' => [
                'orderId' => $orderId,
                'paymenter_invoice_id' => (string) $invoice->id,
                'buyerName' => $userName,
                'buyerEmail' => $userEmail,
            ],
            'checkout' => [
                'redirectURL' => $invoiceRoute,
            ],
            'amount' => $total,
            'currency' => $invoice->currency_code,
        ];
        $apiKey = (string) $this->config('api_key');

        $normalized = $this->normalizeInstanceUrl($this->config('instance_url'));
        $instanceHost = $normalized !== null ? (string) (parse_url($normalized, PHP_URL_HOST) ?: '') : '';

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'token ' . $apiKey,
                ])
                ->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            Log::warning('btcpay_pay', [
                'reason' => 'connection_failed',
                'invoice_id' => $invoice->id,
                'instance_host' => $instanceHost !== '' ? $instanceHost : null,
                'error_summary' => $this->truncateForLog($e->getMessage(), 160),
            ]);

            return $this->payUnableToRedirect($invoice);
        } catch (Throwable $e) {
            Log::warning('btcpay_pay', [
                'reason' => 'request_failed',
                'invoice_id' => $invoice->id,
                'instance_host' => $instanceHost !== '' ? $instanceHost : null,
                'error_class' => $e::class,
                'error_summary' => $this->truncateForLog($e->getMessage(), 160),
            ]);

            return $this->payUnableToRedirect($invoice);
        }

        if ($response->failed()) {
            $this->logPayIssue('warning', 'pay_http_error', $invoice->id, $response);

            return $this->payUnableToRedirect($invoice);
        }

        $responseJson = $response->json();
        if (!is_array($responseJson)) {
            Log::warning('btcpay_pay', [
                'reason' => 'invalid_json_response',
                'invoice_id' => $invoice->id,
                'http_status' => $response->status(),
            ]);

            return $this->payUnableToRedirect($invoice);
        }

        $checkoutUrl = $responseJson['checkoutLink'] ?? null;
        if (empty($checkoutUrl) || !is_string($checkoutUrl) || !$this->isAllowedRedirectUrl($checkoutUrl)) {
            Log::warning('btcpay_pay', [
                'reason' => 'missing_checkout_link',
                'invoice_id' => $invoice->id,
                'http_status' => $response->status(),
            ]);

            return $this->payUnableToRedirect($invoice);
        }

        return $checkoutUrl;
    }

    public function getBTCPayInvoice($invoiceId)
    {
        $base = $this->getEndpoint();
        if ($base === '') {
            return null;
        }
        $apiKey = (string) $this->config('api_key');
        $url = $base . '/' . rawurlencode((string) $invoiceId);
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'token ' . $apiKey,
                ])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        return $response->json();
    }

    public function webhook(Request $request)
    {
        $webhookSecret = $this->config('webhook_secret');
        if ($webhookSecret === null || $webhookSecret === '') {
            Log::error('btcpay_webhook', ['reason' => 'missing_webhook_secret']);

            return response()->json(['error' => 'Configuration error'], 500);
        }

        $rawBody = $request->getContent();
        if ($rawBody === '' || $rawBody === false) {
            Log::warning('btcpay_webhook', ['reason' => 'empty_body']);

            return response()->json(['error' => 'Empty body'], 400);
        }

        $reqSig = (string) ($request->header('BTCPay-Sig') ?? '');
        if ($reqSig === '') {
            Log::warning('btcpay_webhook', ['reason' => 'missing_signature']);

            return response()->json(['error' => 'Missing signature'], 401);
        }

        $digest = 'sha256=' . hash_hmac('sha256', $rawBody, $webhookSecret);
        if (!hash_equals($digest, $reqSig)) {
            Log::warning('btcpay_webhook', [
                'reason' => 'invalid_signature',
                'signature_len' => strlen($reqSig),
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        try {
            $payload = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::warning('btcpay_webhook', ['reason' => 'invalid_json']);

            return response()->json(['error' => 'Invalid JSON'], 400);
        }

        $eventType = is_object($payload) && property_exists($payload, 'type') ? $payload->type : null;
        if ($eventType === null || $eventType === '') {
            Log::warning('btcpay_webhook', ['reason' => 'missing_type']);

            return response()->json(['error' => 'Missing type'], 400);
        }

        if ($eventType !== 'InvoiceSettled') {
            return response()->json(['ignored' => true], 200);
        }

        if (!is_object($payload) || !property_exists($payload, 'invoiceId') || $payload->invoiceId === '' || $payload->invoiceId === null) {
            Log::warning('btcpay_webhook', [
                'reason' => 'invalid_metadata',
                'event_type' => $eventType,
            ]);

            return response()->json(['error' => 'Missing invoice id'], 400);
        }

        $btcPayInvoiceId = (string) $payload->invoiceId;
        if (!property_exists($payload, 'metadata') || !is_object($payload->metadata)) {
            Log::warning('btcpay_webhook', [
                'reason' => 'invalid_metadata',
                'event_type' => $eventType,
                'btcpay_invoice_id' => $btcPayInvoiceId,
            ]);

            return response()->json(['error' => 'Missing metadata'], 400);
        }

        $metadata = $payload->metadata;
        if (!property_exists($metadata, 'orderId') || $metadata->orderId === '' || $metadata->orderId === null) {
            Log::warning('btcpay_webhook', [
                'reason' => 'invalid_metadata',
                'event_type' => $eventType,
                'btcpay_invoice_id' => $btcPayInvoiceId,
            ]);

            return response()->json(['error' => 'Missing order id'], 400);
        }

        $orderId = (string) $metadata->orderId;
        $paymenterInvoiceId = $this->extractInvoiceId($orderId);
        if ($paymenterInvoiceId < 1) {
            Log::warning('btcpay_webhook', [
                'reason' => 'invalid_metadata',
                'event_type' => $eventType,
                'btcpay_invoice_id' => $btcPayInvoiceId,
            ]);

            return response()->json(['error' => 'Invalid order id'], 400);
        }

        if (property_exists($metadata, 'paymenter_invoice_id')
            && $metadata->paymenter_invoice_id !== null
            && $metadata->paymenter_invoice_id !== ''
            && (string) $metadata->paymenter_invoice_id !== (string) $paymenterInvoiceId) {
            Log::warning('btcpay_webhook', [
                'reason' => 'metadata_invoice_mismatch',
                'event_type' => $eventType,
                'btcpay_invoice_id' => $btcPayInvoiceId,
                'invoice_id' => $paymenterInvoiceId,
            ]);

            return response()->json(['error' => 'Invalid metadata'], 400);
        }

        $invoice = Invoice::find($paymenterInvoiceId);
        if (!$invoice) {
            Log::warning('btcpay_webhook', [
                'reason' => 'invoice_not_found',
                'invoice_id' => $paymenterInvoiceId,
                'btcpay_invoice_id' => $btcPayInvoiceId,
                'event_type' => $eventType,
            ]);

            return response()->json(['error' => 'Invoice not found'], 400);
        }

        $btcPayInvoice = $this->getBTCPayInvoice($btcPayInvoiceId);
        if (!is_array($btcPayInvoice)) {
            Log::error('btcpay_webhook', [
                'reason' => 'btcpay_invoice_fetch_failed',
                'invoice_id' => $paymenterInvoiceId,
                'btcpay_invoice_id' => $btcPayInvoiceId,
                'event_type' => $eventType,
            ]);

            return response()->json(['error' => 'Upstream verification failed'], 502);
        }

        $status = $btcPayInvoice['status'] ?? null;
        if (!is_string($status) || strcasecmp($status, 'Settled') !== 0) {
            Log::warning('btcpay_webhook', [
                'reason' => 'invoice_not_settled',
                'invoice_id' => $paymenterInvoiceId,
                'btcpay_invoice_id' => $btcPayInvoiceId,
                'event_type' => $eventType,
                'btcpay_status' => is_string($status) ? $status : 'unknown',
            ]);

            return response()->json(['error' => 'Invoice not settled'], 400);
        }

        if (!array_key_exists('amount', $btcPayInvoice) || !is_numeric($btcPayInvoice['amount'])) {
            Log::warning('btcpay_webhook', [
                'reason' => 'invalid_amount',
                'invoice_id' => $paymenterInvoiceId,
                'btcpay_invoice_id' => $btcPayInvoiceId,
                'event_type' => $eventType,
            ]);

            return response()->json(['error' => 'Invalid amount'], 400);
        }

        $amount = $btcPayInvoice['amount'];
        $transactionId = 'btcpay:' . $btcPayInvoiceId;
        ExtensionHelper::addPayment($paymenterInvoiceId, 'BTCPay', $amount, null, $transactionId);

        return response()->json(['success' => true]);
    }

    public function extractInvoiceId($orderId)
    {
        $orderPrefix = $this->config('order_prefix');
        $invoiceId = (int) substr((string) $orderId, strlen((string) $orderPrefix));

        return $invoiceId;
    }

    private function logPayIssue(string $level, string $reason, int $invoiceId, $response): void
    {
        $row = [
            'reason' => $reason,
            'invoice_id' => $invoiceId,
            'http_status' => $response->status(),
        ];
        $json = $response->json();
        if (is_array($json)) {
            foreach (['code', 'message'] as $k) {
                if (isset($json[$k]) && (is_string($json[$k]) || is_numeric($json[$k]))) {
                    $row['btcpay_' . $k] = $this->truncateForLog((string) $json[$k], 200);
                }
            }
        }
        Log::log($level, 'btcpay_pay', $row);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function validatePayPrerequisites(Invoice $invoice, $total)
    {
        if (!is_numeric($total) || (float) $total <= 0) {
            Log::warning('btcpay_pay', [
                'reason' => 'invalid_amount',
                'invoice_id' => $invoice->id,
            ]);

            return $this->payMisconfigurationRedirect($invoice);
        }

        $missing = [];
        if ($this->normalizeInstanceUrl($this->config('instance_url')) === null) {
            $missing[] = 'instance_url';
        }
        if ($this->config('store_id') === null || trim((string) $this->config('store_id')) === '') {
            $missing[] = 'store_id';
        }
        if ($this->config('api_key') === null || trim((string) $this->config('api_key')) === '') {
            $missing[] = 'api_key';
        }

        if ($missing !== []) {
            Log::warning('btcpay_pay', [
                'reason' => 'config_missing',
                'invoice_id' => $invoice->id,
                'fields' => implode(',', $missing),
            ]);

            return $this->payMisconfigurationRedirect($invoice);
        }

        if ($this->getEndpoint() === '') {
            Log::warning('btcpay_pay', [
                'reason' => 'config_invalid_endpoint',
                'invoice_id' => $invoice->id,
            ]);

            return $this->payMisconfigurationRedirect($invoice);
        }

        return null;
    }

    private function payMisconfigurationRedirect(Invoice $invoice)
    {
        return redirect()->route('invoices.show', ['invoice' => $invoice->id])->with('notification', [
            'message' => 'BTCPay is not configured correctly. Please contact support.',
            'type' => 'error',
        ]);
    }

    private function payUnableToRedirect(Invoice $invoice)
    {
        return redirect()->route('invoices.show', ['invoice' => $invoice->id])->with('notification', [
            'message' => 'Unable to start BTCPay payment. Please try again later or contact support.',
            'type' => 'error',
        ]);
    }

    private function normalizeInstanceUrl($raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $url = rtrim($trimmed, '/');
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private function isAllowedRedirectUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        return in_array($scheme, ['http', 'https'], true);
    }

    private function truncateForLog(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . '…';
    }
}
