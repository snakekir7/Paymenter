<?php

namespace Paymenter\Extensions\Gateways\YooMoneyWallet;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

#[ExtensionMeta(
    name: 'YooMoney Wallet',
    description: 'Accept RUB payments via YooMoney wallet (HTTP notifications, personal wallet mode).',
    version: '1.0.0',
    author: 'Paymenter',
    url: 'https://yoomoney.ru',
    icon: '/images/gateways/yoomoney.svg'
)]
class YooMoneyWallet extends Gateway
{
    private const PROPERTY_LABEL = 'yoomoney_wallet_label';

    private const PROPERTY_LABEL_NONCE = 'yoomoney_wallet_label_nonce';

    /**
     * Official form POSTs to this URL. We return a GET redirect with the same parameters as a
     * shortcut (browser opens YooMoney with a query string). Before production, confirm GET is
     * accepted or replace with an auto-submit POST form view.
     */
    private const QUICKPAY_BASE = 'https://yoomoney.ru/quickpay/confirm';

    public function boot(): void
    {
        require __DIR__ . '/routes.php';
    }

    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'wallet_account',
                'label' => 'Receiver wallet / account number',
                'type' => 'text',
                'required' => true,
                'description' => 'YooMoney wallet ID shown to the payer (receiver).',
            ],
            [
                'name' => 'notification_secret',
                'label' => 'Notification secret (HMAC key)',
                'type' => 'password',
                'required' => true,
                'encrypted' => true,
                'description' => 'Shared secret used to verify the sign field on HTTP notifications. Never share or log.',
            ],
            [
                'name' => 'label_prefix',
                'label' => 'Label prefix',
                'type' => 'text',
                'required' => false,
                'description' => 'Optional prefix for payment labels (e.g. prom_). Default prom_ if empty.',
            ],
            [
                'name' => 'accepted_currency',
                'label' => 'Accepted invoice currency (ISO code)',
                'type' => 'text',
                'required' => false,
                'description' => 'Default RUB. Notifications must still use currency code 643.',
            ],
            [
                'name' => 'conversion_mode',
                'label' => 'Conversion mode',
                'type' => 'select',
                'required' => false,
                'options' => [
                    'none' => 'None — RUB invoices only (recommended)',
                    'on' => 'On — allow non-RUB when it matches Accepted currency (FX in pay() not implemented)',
                ],
                'description' => 'When None or off, only RUB invoices see this gateway. When On, invoice currency must match Accepted currency.',
            ],
            [
                'name' => 'min_amount',
                'label' => 'Minimum amount (RUB)',
                'type' => 'number',
                'required' => false,
                'description' => 'Minimum payable amount; leave empty for no minimum.',
            ],
            [
                'name' => 'max_amount',
                'label' => 'Maximum amount (RUB)',
                'type' => 'number',
                'required' => false,
                'description' => 'Maximum payable amount; leave empty for no maximum.',
            ],
            [
                'name' => 'amount_tolerance',
                'label' => 'Amount tolerance (RUB)',
                'type' => 'number',
                'required' => false,
                'description' => 'Maximum difference between notification amount and invoice remaining. Default 0 (exact).',
            ],
            [
                'name' => 'test_mode',
                'label' => 'Test mode',
                'type' => 'checkbox',
                'required' => false,
                'description' => 'Does not disable signature checks. test_notification still does not credit the invoice.',
            ],
            [
                'name' => 'enable_p2p_incoming',
                'label' => 'Accept p2p-incoming notifications',
                'type' => 'checkbox',
                'required' => false,
            ],
            [
                'name' => 'enable_card_incoming',
                'label' => 'Accept card-incoming notifications',
                'type' => 'checkbox',
                'required' => false,
            ],
            [
                'name' => 'default_payment_type',
                'label' => 'Default quickpay paymentType (when both wallet and card are enabled)',
                'type' => 'select',
                'required' => false,
                'options' => [
                    'PC' => 'PC — pay from YooMoney wallet',
                    'AC' => 'AC — pay from bank card',
                ],
                'description' => 'Used only when both p2p and card notifications are enabled. Official docs: paymentType PC or AC.',
            ],
            [
                'name' => 'notification_url_hint',
                'label' => 'Notification URL (reference only)',
                'type' => 'text',
                'required' => false,
                'description' => 'Register this URL in YooMoney for HTTP notifications: POST /extensions/yoomoney_wallet/notification on your billing host. Not used by code.',
            ],
        ];
    }

    public function pay(Invoice $invoice, $total)
    {
        if (!$this->canUseGateway((float) $total, $invoice->currency_code, 'invoice', [])) {
            throw new DisplayException(__('This payment method is not available for this invoice.'));
        }

        if (!$this->invoiceCurrencySupportedForPay($invoice)) {
            throw new DisplayException(__('This payment method only supports configured wallet currencies; FX is not implemented in this version.'));
        }

        $label = $this->buildLabel($invoice);
        $invoice->properties()->updateOrCreate(
            ['key' => self::PROPERTY_LABEL],
            ['value' => $label]
        );

        $wallet = (string) $this->config('wallet_account');
        $sum = $this->formatAmountForQuickpay((float) $total);
        $successUrl = route('invoices.show', $invoice);

        $query = http_build_query([
            'receiver' => $wallet,
            'quickpay-form' => 'button',
            'paymentType' => $this->quickpayPaymentType(),
            'sum' => $sum,
            'label' => $label,
            'successURL' => $successUrl,
        ], '', '&', PHP_QUERY_RFC3986);

        return self::QUICKPAY_BASE.'?'.$query;
    }

    public function notification(Request $request)
    {
        if (!$request->isMethod('POST')) {
            return response('Method Not Allowed', 405);
        }

        $payload = $request->post();
        if (!is_array($payload) || $payload === []) {
            return response('Bad Request', 400);
        }

        foreach (['notification_type', 'operation_id', 'amount', 'currency'] as $key) {
            if (!array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                $this->safeLog('warning', 'yoomoney_wallet.notification.missing_field', ['field' => $key]);

                return response('Bad Request', 400);
            }
        }

        $isTestPing = $this->isTestNotification($payload);
        /*
         * YooMoney allows an empty label on notifications; this gateway only accepts non-empty labels
         * that match a stored Paymenter invoice label so we can credit the correct invoice safely.
         * Test pings (test_notification) may omit or empty the label per provider behaviour.
         */
        if (!$isTestPing) {
            if (!array_key_exists('label', $payload) || $payload['label'] === '' || $payload['label'] === null) {
                $this->safeLog('warning', 'yoomoney_wallet.notification.missing_label', []);

                return response('Bad Request', 400);
            }
        }

        $sign = $payload['sign'] ?? null;
        if ($sign === null || $sign === '') {
            $this->safeLog('warning', 'yoomoney_wallet.notification.missing_sign', []);

            return response('Bad Request', 400);
        }

        // Reject sha1-only validation paths: valid HMAC sign is mandatory (never accept sha1_hash alone).
        if (!$this->verifySign($payload)) {
            $this->safeLog('warning', 'yoomoney_wallet.notification.bad_sign', [
                'operation_id' => (string) $payload['operation_id'],
                'notification_type' => (string) $payload['notification_type'],
            ]);

            return response('Unauthorized', 401);
        }

        if ($isTestPing) {
            if ($this->truthyConfig('test_mode')) {
                $this->safeLog('info', 'yoomoney_wallet.notification.test_ack', [
                    'test_mode' => true,
                ]);
            } else {
                $this->safeLog('info', 'yoomoney_wallet.notification.test_ignored', []);
            }

            return response('OK', 200);
        }

        $notificationType = (string) $payload['notification_type'];

        if ($notificationType === 'p2p-incoming' && !$this->truthyConfig('enable_p2p_incoming', true)) {
            $this->safeLog('warning', 'yoomoney_wallet.notification.p2p_disabled', []);

            return response('Forbidden', 403);
        }

        if ($notificationType === 'card-incoming' && !$this->truthyConfig('enable_card_incoming', false)) {
            $this->safeLog('warning', 'yoomoney_wallet.notification.card_disabled', []);

            return response('Forbidden', 403);
        }

        if (!in_array($notificationType, ['p2p-incoming', 'card-incoming'], true)) {
            $this->safeLog('warning', 'yoomoney_wallet.notification.unhandled_type', [
                'notification_type' => $notificationType,
            ]);

            return response('Unprocessable', 422);
        }

        $currencyRaw = (string) $payload['currency'];
        if ($currencyRaw !== '643') {
            $this->safeLog('warning', 'yoomoney_wallet.notification.currency_mismatch', [
                'currency' => $currencyRaw,
            ]);

            return response('Bad Request', 400);
        }

        $label = (string) $payload['label'];
        $invoice = $this->resolveInvoiceFromLabel($label);
        if (!$invoice) {
            $this->safeLog('warning', 'yoomoney_wallet.notification.unknown_label', []);

            return response('Not Found', 404);
        }

        if ($invoice->status === Invoice::STATUS_CANCELLED) {
            return response('Gone', 410);
        }

        if ($invoice->status === Invoice::STATUS_PAID) {
            $this->safeLog('info', 'yoomoney_wallet.notification.already_paid', [
                'invoice_id' => $invoice->id,
            ]);

            return response('OK', 200);
        }

        $expected = $this->expectedAmount($invoice);
        $actual = $this->normalizeMoneyString((string) $payload['amount']);
        if (!$this->amountsMatch($expected, $actual)) {
            $this->safeLog('warning', 'yoomoney_wallet.notification.amount_mismatch', [
                'invoice_id' => $invoice->id,
                'expected' => $expected,
                'actual' => $actual,
            ]);

            return response('Bad Request', 400);
        }

        $operationId = trim((string) $payload['operation_id']);
        if ($operationId === '') {
            return response('Bad Request', 400);
        }

        $amountFloat = (float) $actual;
        ExtensionHelper::addPayment($invoice->id, 'YooMoneyWallet', $amountFloat, null, $operationId);

        $this->safeLog('info', 'yoomoney_wallet.notification.accepted', [
            'invoice_id' => $invoice->id,
            'operation_id' => $operationId,
            'amount' => $amountFloat,
            'currency' => '643',
            'notification_type' => $notificationType,
            'receiver_wallet_masked' => $this->maskWallet((string) $this->config('wallet_account')),
        ]);

        return response('OK', 200);
    }

    public function canUseGateway($total, $currency, $type, $items = []): bool
    {
        if (!$this->configComplete()) {
            return false;
        }

        if ((float) $total <= 0) {
            return false;
        }

        if (!$this->truthyConfig('enable_p2p_incoming', true) && !$this->truthyConfig('enable_card_incoming', false)) {
            return false;
        }

        $accepted = strtoupper((string) ($this->config('accepted_currency') ?: 'RUB'));
        $currencyUpper = strtoupper((string) $currency);

        if ($this->conversionModeIsOff()) {
            if ($currencyUpper !== 'RUB') {
                return false;
            }
        } else {
            if ($currencyUpper !== $accepted) {
                return false;
            }
        }

        $min = $this->config('min_amount');
        if ($min !== null && $min !== '' && (float) $total < (float) $min) {
            return false;
        }

        $max = $this->config('max_amount');
        if ($max !== null && $max !== '' && (float) $total > (float) $max) {
            return false;
        }

        return true;
    }

    private function buildLabel(Invoice $invoice): string
    {
        $rawPrefix = (string) ($this->config('label_prefix') ?? '');
        $prefix = $rawPrefix !== '' ? rtrim($rawPrefix, '_').'_' : 'prom_';

        $nonce = bin2hex(random_bytes(4));
        $secret = (string) $this->config('notification_secret');
        $checksum = substr(hash_hmac('sha256', $invoice->id.'|'.$nonce.'|yoomoney_wallet', $secret), 0, 8);

        $invoice->properties()->updateOrCreate(
            ['key' => self::PROPERTY_LABEL_NONCE],
            ['value' => $nonce]
        );

        return $prefix.'inv_'.$invoice->id.'_'.$checksum;
    }

    /**
     * @return int|null Invoice id from label pattern
     */
    private function parseLabel(string $label): ?int
    {
        $rawPrefix = (string) ($this->config('label_prefix') ?? '');
        $escapedPrefix = preg_quote($rawPrefix !== '' ? rtrim($rawPrefix, '_').'_' : 'prom_', '/');
        if (preg_match('/^'.$escapedPrefix.'inv_(\d+)_([a-f0-9]{8})$/', $label, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Verify HMAC-SHA256 sign per implementation spec.
     *
     * Exact encoding must be verified against YooMoney official test vector before live usage.
     */
    private function verifySign(array $payload): bool
    {
        $provided = $payload['sign'] ?? '';
        if ($provided === '' || $provided === null) {
            return false;
        }

        $secret = (string) $this->config('notification_secret');
        if ($secret === '') {
            return false;
        }

        $copy = $payload;
        unset($copy['sign']);
        $expectedHex = hash_hmac('sha256', $this->canonicalSigningString($copy), $secret);

        return hash_equals(strtolower($expectedHex), strtolower((string) $provided));
    }

    private function expectedAmount(Invoice $invoice): float
    {
        return (float) $invoice->fresh()->remaining;
    }

    private function amountsMatch($expected, $actual): bool
    {
        $e = (float) $expected;
        $a = (float) $actual;
        $tol = (float) ($this->config('amount_tolerance') ?? 0);

        return abs($e - $a) <= $tol + 1e-9;
    }

    private function maskWallet(?string $wallet): string
    {
        if ($wallet === null || $wallet === '') {
            return '';
        }
        $len = strlen($wallet);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', max(0, $len - 4)).substr($wallet, -4);
    }

    private function safeLog(string $level, string $message, array $context = []): void
    {
        $redacted = $context;
        foreach (array_keys($redacted) as $key) {
            if (in_array($key, ['secret', 'notification_secret', 'sign', 'sha1_hash'], true)) {
                unset($redacted[$key]);
            }
        }
        Log::log($level, $message, $redacted);
    }

    private function canonicalSigningString(array $withoutSign): string
    {
        ksort($withoutSign, SORT_STRING);
        $pairs = [];
        foreach ($withoutSign as $k => $v) {
            if ($v === null) {
                continue;
            }
            $pairs[] = rawurlencode((string) $k).'='.rawurlencode((string) $v);
        }

        return implode('&', $pairs);
    }

    private function configComplete(): bool
    {
        $wallet = trim((string) ($this->config('wallet_account') ?? ''));
        $secret = (string) ($this->config('notification_secret') ?? '');

        return $wallet !== '' && $secret !== '';
    }

    private function conversionModeIsOff(): bool
    {
        $mode = strtolower((string) ($this->config('conversion_mode') ?? 'none'));

        return in_array($mode, ['', 'none', 'off'], true);
    }

    private function truthyConfig(string $key, ?bool $default = null): bool
    {
        $v = $this->config($key);
        if ($v === null && $default !== null) {
            return $default;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN) || $v === '1' || $v === 1;
    }

    private function invoiceCurrencySupportedForPay(Invoice $invoice): bool
    {
        if ($this->conversionModeIsOff()) {
            return strtoupper($invoice->currency_code) === 'RUB';
        }

        $accepted = strtoupper((string) ($this->config('accepted_currency') ?: 'RUB'));

        return strtoupper($invoice->currency_code) === $accepted;
    }

    /**
     * Official HTTP notifications use boolean parameter test_notification (not notification_type).
     */
    private function isTestNotification(array $payload): bool
    {
        if (!array_key_exists('test_notification', $payload)) {
            return false;
        }

        $v = $payload['test_notification'];

        return $v === true || $v === 1 || $v === '1' || strtolower((string) $v) === 'true';
    }

    private function quickpayPaymentType(): string
    {
        $p2p = $this->truthyConfig('enable_p2p_incoming', true);
        $card = $this->truthyConfig('enable_card_incoming', false);

        if ($p2p && !$card) {
            return 'PC';
        }

        if (!$p2p && $card) {
            return 'AC';
        }

        if ($p2p && $card) {
            $def = strtoupper((string) ($this->config('default_payment_type') ?: 'PC'));

            return in_array($def, ['PC', 'AC'], true) ? $def : 'PC';
        }

        return 'PC';
    }

    private function formatAmountForQuickpay(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function normalizeMoneyString(string $amount): string
    {
        $amount = str_replace(',', '.', trim($amount));

        return number_format((float) $amount, 2, '.', '');
    }

    private function resolveInvoiceFromLabel(string $label): ?Invoice
    {
        $prop = Property::query()
            ->where('key', self::PROPERTY_LABEL)
            ->where('value', $label)
            ->where('model_type', Invoice::class)
            ->first();

        if ($prop) {
            $invoice = Invoice::find($prop->model_id);
            if ($invoice) {
                return $invoice;
            }
        }

        $id = $this->parseLabel($label);
        if ($id === null) {
            return null;
        }

        $invoice = Invoice::find($id);
        if (!$invoice) {
            return null;
        }

        $stored = $invoice->properties->where('key', self::PROPERTY_LABEL)->first()?->value;
        if ($stored !== $label) {
            $this->safeLog('warning', 'yoomoney_wallet.notification.label_property_mismatch', [
                'invoice_id' => $invoice->id,
            ]);

            return null;
        }

        return $invoice;
    }
}
