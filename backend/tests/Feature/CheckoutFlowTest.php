<?php

namespace Tests\Feature;

use App\Domain\Payments\GatewayManager;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\PaymentException;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\GiftCode;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private array $billing = ['billing_name' => 'Player One', 'billing_country' => 'SA', 'accept_terms' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    private function checkout($user): Payment
    {
        $this->actingAs($user)->post('/ar/checkout', $this->billing)->assertRedirectContains('/dev/fake-pay/');

        return Payment::latest('id')->firstOrFail();
    }

    private function pay(Payment $payment, string $outcome = 'pay')
    {
        return $this->post(route('dev.fake-pay.complete', $payment->gateway_ref), ['outcome' => $outcome]);
    }

    public function test_full_purchase_grants_entitlement_only_after_gateway_confirms(): void
    {
        $user = $this->customer();
        $pkg = $this->package($this->game(), 6900);
        $this->actingAs($user)->post('/ar/cart/items', ['package' => $pkg->slug])->assertSessionHasNoErrors();

        $payment = $this->checkout($user);
        $order = $payment->order;
        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);
        $this->assertSame(6900, $order->total_amount);
        $this->assertSame(900, $order->vat_amount);
        $this->assertFalse($user->owns($pkg), 'nothing is granted before payment is verified');

        $this->pay($payment)->assertRedirect(route('checkout.return', $payment->idempotency_key));
        $this->get(route('checkout.return', $payment->idempotency_key))->assertRedirect();

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->invoice_number);
        $this->assertTrue($user->owns($pkg));
        $this->assertSame(0, CartItem::where('user_id', $user->id)->count());
        $this->get(route('orders.show', ['order' => $order, 'locale' => 'ar']))->assertOk()->assertSee($order->invoice_number);
    }

    public function test_hitting_the_return_url_without_paying_grants_nothing(): void
    {
        $user = $this->customer();
        $pkg = $this->package($this->game(), 6900);
        CartItem::create(['user_id' => $user->id, 'package_id' => $pkg->id]);
        $payment = $this->checkout($user);

        $this->get(route('checkout.return', $payment->idempotency_key));

        $this->assertSame(OrderStatus::AwaitingPayment, $payment->order->fresh()->status);
        $this->assertFalse($user->owns($pkg));
    }

    public function test_failed_payment_marks_order_failed_and_can_be_retried(): void
    {
        $user = $this->customer();
        $pkg = $this->package($this->game(), 3900);
        CartItem::create(['user_id' => $user->id, 'package_id' => $pkg->id]);
        $payment = $this->checkout($user);

        $this->pay($payment, 'fail');
        $this->get(route('checkout.return', $payment->idempotency_key));

        $this->assertSame(OrderStatus::Failed, $payment->order->fresh()->status);
        $this->assertFalse($user->owns($pkg));
        $this->post(route('orders.pay', ['order' => $payment->order, 'locale' => 'ar']))->assertRedirectContains('/dev/fake-pay/');
    }

    public function test_webhook_fulfils_once_and_ignores_replays(): void
    {
        $user = $this->customer();
        $pkg = $this->package($this->game(), 3900);
        CartItem::create(['user_id' => $user->id, 'package_id' => $pkg->id]);
        $payment = $this->checkout($user);
        FakeGateway::setOutcome($payment->gateway_ref, PaymentStatus::Paid);

        $this->postJson('/webhooks/payments/fake', ['ref' => $payment->gateway_ref])->assertOk()->assertJson(['status' => 'ok']);
        $this->postJson('/webhooks/payments/fake', ['ref' => $payment->gateway_ref])->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, PaymentWebhookEvent::count());
        $this->assertSame(1, Entitlement::where('user_id', $user->id)->count());
        $this->assertSame(OrderStatus::Paid, $payment->order->fresh()->status);
    }

    public function test_unverified_users_cannot_check_out(): void
    {
        $user = $this->customer();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($user)->get('/ar/checkout')->assertRedirect(route('verification.notice'));
    }

    public function test_coupon_is_applied_server_side_and_redemption_counted(): void
    {
        $user = $this->customer();
        $pkg = $this->package($this->game(), 10000);
        CartItem::create(['user_id' => $user->id, 'package_id' => $pkg->id]);
        Coupon::create(['code' => 'WELCOME', 'kind' => 'percent', 'value' => 20]);

        $this->actingAs($user)->post('/ar/cart/coupon', ['code' => 'welcome'])->assertSessionHasNoErrors();
        $payment = $this->checkout($user);
        $this->assertSame(8000, $payment->amount);

        $this->pay($payment);
        $this->get(route('checkout.return', $payment->idempotency_key));
        $this->assertSame(1, Coupon::first()->redeemed_count);

        // per-user limit of 1: second use is refused
        $other = $this->package($this->game(), 5000);
        CartItem::create(['user_id' => $user->id, 'package_id' => $other->id]);
        $this->post('/ar/cart/coupon', ['code' => 'WELCOME'])->assertSessionHasErrors('code');
    }

    public function test_fully_discounted_order_is_fulfilled_without_gateway(): void
    {
        $user = $this->customer();
        $pkg = $this->package($this->game(), 3000);
        CartItem::create(['user_id' => $user->id, 'package_id' => $pkg->id]);
        Coupon::create(['code' => 'FREE', 'kind' => 'percent', 'value' => 100]);
        $this->actingAs($user)->post('/ar/cart/coupon', ['code' => 'FREE']);

        $this->post('/ar/checkout', $this->billing)->assertRedirect();

        $this->assertSame(0, Payment::count());
        $this->assertTrue($user->owns($pkg));
        $this->assertSame(OrderStatus::Paid, Order::first()->status);
    }

    public function test_bundle_grants_included_packages(): void
    {
        $game = $this->game();
        $full = $this->package($game, 11900, ['dialogues', 'menus']);
        $bundle = $this->package($game, 14900, ['codex'], ['is_bundle' => true]);
        $bundle->inclusions()->sync([$full->id]);
        $user = $this->customer();
        CartItem::create(['user_id' => $user->id, 'package_id' => $bundle->id]);

        $payment = $this->checkout($user);
        $this->pay($payment);
        $this->get(route('checkout.return', $payment->idempotency_key));

        $this->assertTrue($user->owns($bundle));
        $this->assertTrue($user->owns($full));
        $this->assertNotNull(Entitlement::where('package_id', $full->id)->first()->parent_entitlement_id);
    }

    public function test_gift_purchase_creates_code_that_another_user_redeems_once(): void
    {
        $buyer = $this->customer();
        $friend = $this->customer();
        $pkg = $this->package($this->game(), 3900);
        $this->actingAs($buyer)->post('/ar/cart/items', ['package' => $pkg->slug, 'as_gift' => 1]);

        $payment = $this->checkout($buyer);
        $this->pay($payment);
        $this->get(route('checkout.return', $payment->idempotency_key));

        $this->assertFalse($buyer->owns($pkg), 'a gift does not go to the buyer');
        $code = GiftCode::firstOrFail()->code_encrypted;
        $this->get(route('orders.show', ['order' => $payment->order, 'locale' => 'ar']))->assertSee($code);

        $this->actingAs($friend)->post('/ar/gift', ['code' => strtolower($code)])->assertRedirect(route('library'));
        $this->assertTrue($friend->owns($pkg));
        $this->actingAs($this->customer())->post('/ar/gift', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_users_cannot_see_each_others_orders(): void
    {
        $user = $this->customer();
        $pkg = $this->package($this->game(), 3900);
        CartItem::create(['user_id' => $user->id, 'package_id' => $pkg->id]);
        $payment = $this->checkout($user);

        $this->actingAs($this->customer())->get(route('orders.show', ['order' => $payment->order, 'locale' => 'ar']))->assertNotFound();
        $this->get(route('checkout.return', $payment->idempotency_key))->assertNotFound();
    }

    public function test_fake_gateway_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(PaymentException::class);
        app(GatewayManager::class)->get('fake');
    }
}
