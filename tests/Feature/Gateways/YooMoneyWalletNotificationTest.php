<?php

namespace Tests\Feature\Gateways;

use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\YooMoneyWallet\YooMoneyWallet;
use Tests\TestCase;

class YooMoneyWalletNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-notification-secret-not-for-production';

    /**
     * Notification handler is exercised via a fixed path in tests. Named route in routes.php
     * remains the production contract; PHPUnit bootstrap may not keep Route::has in sync.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('extensions.gateways.yoomoney_wallet.notification')) {
            require base_path('extensions/Gateways/YooMoneyWallet/routes.php');
            Route::getRoutes()->refreshNameLookups();
        }

        if (! Route::has('extensions.gateways.yoomoney_wallet.notification')) {
            Route::post('/extensions/yoomoney_wallet/notification', [\Paymenter\Extensions\Gateways\YooMoneyWallet\YooMoneyWallet::class, 'notification'])
                ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
                ->name('extensions.gateways.yoomoney_wallet.notification');

            Route::getRoutes()->refreshNameLookups();
        }
    }

    private function notificationEndpoint(): string
    {
        return '/extensions/yoomoney_wallet/notification';
    }

    private function baseConfig(): array
    {
        return [
            'wallet_account' => '4100123456789',
            'notification_secret' => self::SECRET,
            'label_prefix' => 'prom_',
            'accepted_currency' => 'RUB',
            'conversion_mode' => 'none',
            'enable_p2p_incoming' => '1',
            'enable_card_incoming' => '0',
            'test_mode' => '0',
            'amount_tolerance' => '0',
            'default_payment_type' => 'PC',
        ];
    }

    private function seedGateway(array $config): Gateway
    {
        $gateway = Gateway::create([
            'name' => 'YooMoney Wallet Test',
            'extension' => 'YooMoneyWallet',
            'type' => 'gateway',
            'enabled' => true,
        ]);

        foreach ($config as $key => $value) {
            Setting::create([
                'key' => $key,
                'value' => (string) $value,
                'type' => 'string',
                'encrypted' => false,
                'settingable_id' => $gateway->id,
                'settingable_type' => $gateway->getMorphClass(),
            ]);
        }

        return $gateway;
    }

    private function signPayload(array $payload, string $secret): string
    {
        $copy = $payload;
        unset($copy['sign']);
        ksort($copy, SORT_STRING);
        $pairs = [];
        foreach ($copy as $k => $v) {
            if ($v === null) {
                continue;
            }
            $pairs[] = rawurlencode((string) $k).'='.rawurlencode((string) $v);
        }
        $canonical = implode('&', $pairs);

        return strtolower(hash_hmac('sha256', $canonical, $secret));
    }

    private function makeRubInvoice(float $total = 100.00): Invoice
    {
        $user = User::factory()->create();

        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'RUB',
            'status' => Invoice::STATUS_PENDING,
        ]);
        $invoice->items()->create([
            'description' => 'Test',
            'quantity' => 1,
            'price' => $total,
        ]);

        return $invoice->fresh();
    }

    public function test_can_use_gateway_hides_usd_invoice(): void
    {
        $gw = new YooMoneyWallet($this->baseConfig());
        $this->assertFalse($gw->canUseGateway(10.0, 'USD', 'invoice', []));
    }

    public function test_can_use_gateway_allows_rub_with_complete_config(): void
    {
        $gw = new YooMoneyWallet($this->baseConfig());
        $this->assertTrue($gw->canUseGateway(10.0, 'RUB', 'invoice', []));
    }

    public function test_pay_stores_label_property_and_url_contains_receiver_sum_label(): void
    {
        $this->seedGateway($this->baseConfig());
        $gw = new YooMoneyWallet($this->baseConfig());
        $invoice = $this->makeRubInvoice(99.50);

        $url = $gw->pay($invoice, 99.50);
        $this->assertStringContainsString('https://yoomoney.ru/quickpay/confirm', $url);
        $this->assertStringNotContainsString('confirm.xml', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('4100123456789', $query['receiver'] ?? null);
        $this->assertSame('button', $query['quickpay-form'] ?? null);
        $this->assertSame('PC', $query['paymentType'] ?? null);
        $this->assertSame('99.50', $query['sum'] ?? null);

        $invoice->refresh();
        $label = $invoice->properties->where('key', 'yoomoney_wallet_label')->first()?->value;
        $this->assertNotNull($label);
        $this->assertStringContainsString('prom_inv_'.$invoice->id.'_', $label);
        $this->assertSame($label, $query['label'] ?? null);
    }

    public function test_pay_uses_ac_when_only_card_notifications_enabled(): void
    {
        $cfg = $this->baseConfig();
        $cfg['enable_p2p_incoming'] = '0';
        $cfg['enable_card_incoming'] = '1';
        $this->seedGateway($cfg);
        $gw = new YooMoneyWallet($cfg);
        $invoice = $this->makeRubInvoice(50.00);

        $url = $gw->pay($invoice, 50.00);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('AC', $query['paymentType'] ?? null);
        $this->assertSame('button', $query['quickpay-form'] ?? null);
    }

    public function test_valid_signed_notification_creates_payment(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-unique-1',
            'amount' => '100.00',
            'currency' => '643',
            'label' => $label,
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['sign']);

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertOk();

        $invoice->refresh();
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->status);
        $this->assertEquals(1, $invoice->transactions()->count());
        $this->assertEquals('op-unique-1', $invoice->transactions->first()->transaction_id);
    }

    public function test_valid_signed_notification_with_sha1_hash_in_payload(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-with-sha1',
            'amount' => '100.00',
            'currency' => '643',
            'label' => $label,
            'sha1_hash' => '8693ddf402fe5dcc4c4744d466cabada2628148c',
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertOk();
        $this->assertEquals(1, $invoice->fresh()->transactions()->count());
    }

    public function test_valid_signed_notification_without_sha1_hash(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-no-sha1',
            'amount' => '100.00',
            'currency' => '643',
            'label' => $label,
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertOk();
        $this->assertEquals(1, $invoice->fresh()->transactions()->count());
    }

    public function test_invalid_sign_rejects_and_creates_no_transaction(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-bad-sign',
            'amount' => '100.00',
            'currency' => '643',
            'label' => $label,
            'sign' => 'deadbeef',
        ];

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertUnauthorized();

        $invoice->refresh();
        $this->assertEquals(0, $invoice->transactions()->count());
    }

    public function test_missing_sign_rejects(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-no-sign',
            'amount' => '100.00',
            'currency' => '643',
            'label' => $label,
        ];

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertBadRequest();

        $this->assertEquals(0, $invoice->fresh()->transactions()->count());
    }

    public function test_sha1_hash_only_without_sign_rejects(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-sha1-only',
            'amount' => '100.00',
            'currency' => '643',
            'label' => $label,
            'sha1_hash' => 'abc123fake',
        ];

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertBadRequest();
        $this->assertEquals(0, $invoice->fresh()->transactions()->count());
    }

    public function test_amount_mismatch_rejects(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-amt',
            'amount' => '50.00',
            'currency' => '643',
            'label' => $label,
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertBadRequest();
        $this->assertEquals(0, $invoice->fresh()->transactions()->count());
    }

    public function test_currency_mismatch_rejects(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-ccy',
            'amount' => '100.00',
            'currency' => '840',
            'label' => $label,
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertBadRequest();
    }

    public function test_empty_label_rejects_without_credit(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => 'prom_inv_'.$invoice->id.'_abcd1234']
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-empty-label',
            'amount' => '100.00',
            'currency' => '643',
            'label' => '',
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertBadRequest();
        $this->assertEquals(0, $invoice->fresh()->transactions()->count());
    }

    public function test_unknown_label_rejects(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-unknown-label',
            'amount' => '100.00',
            'currency' => '643',
            'label' => 'wrong_label_not_stored',
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertNotFound();
    }

    public function test_duplicate_operation_id_is_idempotent(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-dup-1',
            'amount' => '100.00',
            'currency' => '643',
            'label' => $label,
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);

        $this->post($this->notificationEndpoint(), $payload)->assertOk();
        $this->post($this->notificationEndpoint(), $payload)->assertOk();

        $invoice->refresh();
        $this->assertEquals(1, $invoice->transactions()->where('transaction_id', 'op-dup-1')->count());
    }

    public function test_card_incoming_disabled_rejects(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'card-incoming',
            'operation_id' => 'op-card',
            'amount' => '100.00',
            'currency' => '643',
            'label' => $label,
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertForbidden();
        $this->assertEquals(0, $invoice->fresh()->transactions()->count());
    }

    public function test_card_incoming_enabled_accepted(): void
    {
        $cfg = $this->baseConfig();
        $cfg['enable_card_incoming'] = '1';
        $this->seedGateway($cfg);
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'card-incoming',
            'operation_id' => 'op-card-ok',
            'amount' => '100.00',
            'currency' => '643',
            'label' => $label,
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertOk();
        $this->assertEquals(1, $invoice->fresh()->transactions()->count());
    }

    public function test_test_notification_does_not_credit(): void
    {
        $this->seedGateway($this->baseConfig());
        $invoice = $this->makeRubInvoice(100.00);
        $label = 'prom_inv_'.$invoice->id.'_abcd1234';
        $invoice->properties()->updateOrCreate(
            ['key' => 'yoomoney_wallet_label'],
            ['value' => $label]
        );

        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-test',
            'amount' => '100.00',
            'currency' => '643',
            'label' => $label,
            'test_notification' => 'true',
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);

        $response = $this->post($this->notificationEndpoint(), $payload);
        $response->assertOk();
        $this->assertEquals(0, $invoice->fresh()->transactions()->count());
    }

    public function test_test_notification_true_integer_does_not_credit(): void
    {
        $this->seedGateway($this->baseConfig());
        $payload = [
            'notification_type' => 'p2p-incoming',
            'operation_id' => 'op-test-int',
            'amount' => '1.00',
            'currency' => '643',
            'label' => 'x',
            'test_notification' => '1',
        ];
        $payload['sign'] = $this->signPayload($payload, self::SECRET);

        $this->post($this->notificationEndpoint(), $payload)->assertOk();
    }

}
