<?php

namespace Tests\Feature;

use App\Domain\Commerce\RefundOrderItem;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    public function test_refunding_a_bundle_revokes_it_and_its_children(): void
    {
        $this->seedReference();
        $game = $this->game();
        $full = $this->package($game, 11900);
        $bundle = $this->package($game, 14900, ['codex'], ['is_bundle' => true]);
        $bundle->inclusions()->sync([$full->id]);
        $user = $this->customer();
        CartItem::create(['user_id' => $user->id, 'package_id' => $bundle->id]);

        $this->actingAs($user)->post('/ar/checkout', ['billing_name' => 'P', 'billing_country' => 'SA', 'accept_terms' => '1']);
        $payment = Payment::firstOrFail();
        $this->post(route('dev.fake-pay.complete', $payment->gateway_ref), ['outcome' => 'pay']);
        $this->get(route('checkout.return', $payment->idempotency_key));
        $this->assertTrue($user->owns($full));

        app(RefundOrderItem::class)->handle($payment->order->items()->first(), 'Customer request', $this->staff('finance'));

        $order = $payment->order->fresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertSame(14900, $order->refunded_amount);
        $this->assertFalse($user->owns($bundle));
        $this->assertFalse($user->owns($full));
        $this->assertDatabaseHas('refunds', ['payment_id' => $payment->id, 'status' => 'processed', 'amount' => 14900]);
    }
}
