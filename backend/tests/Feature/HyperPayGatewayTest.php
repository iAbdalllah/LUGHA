<?php

namespace Tests\Feature;

use App\Domain\Payments\Gateways\HyperPayGateway;
use App\Domain\Payments\StartPayment;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class HyperPayGatewayTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = bin2hex(random_bytes(32));
        config([
            'payments.default' => 'hyperpay',
            'payments.hyperpay.access_token' => 'tok_test',
            'payments.hyperpay.webhook_key' => $this->key,
            'payments.hyperpay.entities' => ['mada' => 'ent_mada', 'card' => 'ent_card', 'applepay' => null],
        ]);
    }

    private function order(): Order
    {
        return Order::create(['user_id' => $this->customer()->id, 'status' => 'pending', 'currency' => 'SAR',
            'subtotal_amount' => 6900, 'vat_amount' => 900, 'total_amount' => 6900, 'billing' => ['name' => 'P', 'country' => 'SA']]);
    }

    public function test_only_configured_brands_are_offered(): void
    {
        $this->assertSame(['mada', 'card'], array_keys(app(HyperPayGateway::class)->methodChoices()));
    }

    public function test_mada_checkout_uses_the_mada_entity_and_decimal_amount(): void
    {
        Http::fake(['eu-test.oppwa.com/v1/checkouts' => Http::response(['id' => 'chk_1', 'result' => ['code' => '000.200.100']])]);

        $payment = app(StartPayment::class)->handle($this->order(), 'mada');

        $this->assertSame('chk_1', $payment->gateway_ref);
        $this->assertSame(route('checkout.pay', $payment->idempotency_key), $payment->checkout_url);
        Http::assertSent(fn (HttpRequest $r) => $r['entityId'] === 'ent_mada' && $r['amount'] === '69.00' && $r['paymentType'] === 'DB');

        $this->actingAs($payment->order->user)->get($payment->checkout_url)
            ->assertOk()->assertSee('paymentWidgets.js?checkoutId=chk_1', false)->assertSee('data-brands="MADA"', false);
    }

    public function test_result_codes_map_to_statuses(): void
    {
        $order = $this->order();
        $p = $order->payments()->create(['gateway' => 'hyperpay', 'gateway_ref' => 'chk_1', 'idempotency_key' => (string) Str::ulid(), 'amount' => 6900, 'currency' => 'SAR', 'method' => 'card']);
        $gw = app(HyperPayGateway::class);

        $code = '';
        Http::fake(function () use (&$code) {
            return Http::response(['id' => 'pay_1', 'amount' => '69.00', 'currency' => 'SAR', 'paymentBrand' => 'VISA', 'result' => ['code' => $code]]);
        });
        foreach (['000.100.110' => PaymentStatus::Paid, '000.000.000' => PaymentStatus::Paid, '000.200.000' => PaymentStatus::Pending, '800.100.151' => PaymentStatus::Failed] as $c => $expected) {
            $code = $c;
            $this->assertSame($expected, $gw->fetchStatus($p)->status, $code);
            $this->assertSame(6900, $gw->fetchStatus($p)->amount);
        }
    }

    public function test_decrypts_webhooks_and_rejects_tampering(): void
    {
        $iv = random_bytes(12);
        $body = json_encode(['type' => 'PAYMENT', 'payload' => ['id' => 'pay_1', 'ndc' => 'chk_1', 'result' => ['code' => '000.100.110']]]);
        $cipher = openssl_encrypt($body, 'aes-256-gcm', hex2bin($this->key), OPENSSL_RAW_DATA, $iv, $tag);
        $make = fn ($t) => Request::create('/', 'POST', [], [], [], ['HTTP_X_INITIALIZATION_VECTOR' => bin2hex($iv), 'HTTP_X_AUTHENTICATION_TAG' => bin2hex($t)], bin2hex($cipher));

        $event = app(HyperPayGateway::class)->parseWebhook($make($tag));
        $this->assertSame('chk_1', $event->gatewayRef);
        $this->assertNull(app(HyperPayGateway::class)->parseWebhook($make(str_repeat("\0", 16))));
    }

    public function test_refund_posts_rf_against_the_captured_payment(): void
    {
        Http::fake(['eu-test.oppwa.com/v1/payments/pay_1' => Http::response(['id' => 'rf_1', 'result' => ['code' => '000.100.110']])]);
        $p = $this->order()->payments()->create(['gateway' => 'hyperpay', 'gateway_ref' => 'chk_1', 'idempotency_key' => (string) Str::ulid(),
            'amount' => 6900, 'currency' => 'SAR', 'method' => 'mada', 'raw_last_status' => ['id' => 'pay_1']]);

        $this->assertTrue(app(HyperPayGateway::class)->refund($p, 3000)->ok);
        Http::assertSent(fn (HttpRequest $r) => $r['paymentType'] === 'RF' && $r['amount'] === '30.00' && $r['entityId'] === 'ent_mada');
    }
}
