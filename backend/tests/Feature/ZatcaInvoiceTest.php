<?php

namespace Tests\Feature;

use App\Domain\Commerce\RefundOrderItem;
use App\Domain\Invoicing\ZatcaQr;
use App\Models\CartItem;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class ZatcaInvoiceTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    public function test_tlv_payload_has_the_five_fields_with_byte_lengths(): void
    {
        $payload = ZatcaQr::payload('لغة', '310000000000003', Carbon::parse('2026-11-22 13:04:00', 'Asia/Riyadh'), 6900, 900);

        $this->assertSame([
            1 => 'لغة', 2 => '310000000000003', 3 => '2026-11-22T10:04:00Z', 4 => '69.00', 5 => '9.00',
        ], ZatcaQr::decode($payload));
        $this->assertSame(6, ord(base64_decode($payload)[1]), 'Arabic seller name length is counted in UTF-8 bytes');
    }

    public function test_paid_invoice_and_refund_credit_note_carry_qr_codes(): void
    {
        config(['lugha.seller.name' => 'LUGHA', 'lugha.seller.vat_number' => '310000000000003']);
        $this->seedReference();
        $user = $this->customer();
        $pkg = $this->package($this->game(), 6900);
        CartItem::create(['user_id' => $user->id, 'package_id' => $pkg->id]);

        $this->actingAs($user)->post('/ar/checkout', ['billing_name' => 'P', 'billing_country' => 'SA', 'accept_terms' => '1']);
        $payment = Payment::firstOrFail();
        $this->post(route('dev.fake-pay.complete', $payment->gateway_ref), ['outcome' => 'pay']);
        $this->get(route('checkout.return', $payment->idempotency_key));
        $order = $payment->order->fresh();

        $page = $this->get(route('orders.show', ['order' => $order, 'locale' => 'ar']));
        $page->assertOk()->assertSee('فاتورة ضريبية مبسطة')->assertSee('<svg', false)->assertSee('310000000000003');

        $refund = app(RefundOrderItem::class)->handle($order->items()->first(), 'test', $this->staff('finance'));
        $this->assertMatchesRegularExpression('/^CN-\d{4}-\d{6}$/', $refund->fresh()->credit_note_number);
        $this->assertSame(900, $refund->fresh()->vat_amount);
        $this->get(route('orders.show', ['order' => $order, 'locale' => 'ar']))->assertSee('إشعار دائن')->assertSee($refund->fresh()->credit_note_number);
    }

    public function test_business_buyer_gets_a_tax_invoice(): void
    {
        $this->seedReference();
        $user = $this->customer();
        CartItem::create(['user_id' => $user->id, 'package_id' => $this->package($this->game(), 6900)->id]);
        $this->actingAs($user)->post('/ar/checkout', ['billing_name' => 'شركة', 'billing_country' => 'SA', 'billing_vat_number' => '300000000000003', 'accept_terms' => '1']);
        $payment = Payment::firstOrFail();
        $this->post(route('dev.fake-pay.complete', $payment->gateway_ref), ['outcome' => 'pay']);
        $this->get(route('checkout.return', $payment->idempotency_key));

        $this->get(route('orders.show', ['order' => $payment->order, 'locale' => 'ar']))->assertSee('فاتورة ضريبية')->assertDontSee('فاتورة ضريبية مبسطة')->assertSee('300000000000003');
    }
}
