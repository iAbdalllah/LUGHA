<?php

namespace Tests\Feature;

use App\Domain\Payments\Gateways\MoyasarGateway;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class MoyasarGatewayTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private function payment(): Payment
    {
        $order = Order::create(['user_id' => $this->customer()->id, 'status' => 'pending', 'currency' => 'SAR',
            'subtotal_amount' => 6900, 'vat_amount' => 900, 'total_amount' => 6900]);

        return $order->payments()->create(['gateway' => 'moyasar', 'gateway_ref' => 'inv_123', 'idempotency_key' => (string) Str::ulid(),
            'amount' => 6900, 'currency' => 'SAR']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.moyasar.secret_key' => 'sk_test_x', 'payments.moyasar.webhook_secret' => 'whsec_x']);
    }

    public function test_creates_hosted_invoice_with_amount_in_halalas(): void
    {
        Http::fake(['api.moyasar.com/v1/invoices' => Http::response(['id' => 'inv_123', 'url' => 'https://checkout.moyasar.com/inv_123', 'status' => 'initiated'])]);
        $p = $this->payment();

        $session = app(MoyasarGateway::class)->createCheckout($p->order, $p, 'https://lugha.test/return');

        $this->assertSame('inv_123', $session->gatewayRef);
        Http::assertSent(fn (HttpRequest $r) => $r['amount'] === 6900 && $r['currency'] === 'SAR' && $r->hasHeader('Authorization'));
    }

    public function test_reads_paid_status_from_the_api(): void
    {
        Http::fake(['api.moyasar.com/v1/invoices/inv_123' => Http::response([
            'id' => 'inv_123', 'status' => 'paid', 'amount' => 6900, 'currency' => 'SAR',
            'payments' => [['id' => 'pay_9', 'status' => 'paid', 'source' => ['type' => 'mada']]],
        ])]);

        $status = app(MoyasarGateway::class)->fetchStatus($this->payment());

        $this->assertSame(PaymentStatus::Paid, $status->status);
        $this->assertSame('mada', $status->method);
    }

    public function test_webhook_with_wrong_secret_is_rejected(): void
    {
        $gw = app(MoyasarGateway::class);
        $bad = Request::create('/', 'POST', ['id' => 'evt_1', 'secret_token' => 'nope', 'data' => ['invoice_id' => 'inv_123']]);
        $good = Request::create('/', 'POST', ['id' => 'evt_1', 'secret_token' => 'whsec_x', 'data' => ['invoice_id' => 'inv_123']]);

        $this->assertNull($gw->parseWebhook($bad));
        $this->assertSame('inv_123', $gw->parseWebhook($good)->gatewayRef);
        $this->postJson('/webhooks/payments/moyasar', ['id' => 'evt_2', 'secret_token' => 'nope'])->assertUnauthorized();
    }

    public function test_refunds_the_captured_payment(): void
    {
        Http::fake(['api.moyasar.com/v1/payments/pay_9/refund' => Http::response(['id' => 'pay_9', 'status' => 'refunded'])]);
        $p = $this->payment();
        $p->update(['raw_last_status' => ['payments' => [['id' => 'pay_9', 'status' => 'paid']]]]);

        $this->assertTrue(app(MoyasarGateway::class)->refund($p, 6900)->ok);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payments/pay_9/refund') && $r['amount'] === 6900);
    }
}
