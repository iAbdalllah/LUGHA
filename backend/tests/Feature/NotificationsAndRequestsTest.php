<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CartItem;
use App\Models\Payment;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\OrderPaid;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class NotificationsAndRequestsTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    private function paidOrder($user, int $price = 6900, bool $gift = false)
    {
        CartItem::create(['user_id' => $user->id, 'package_id' => $this->package($this->game(), $price)->id, 'as_gift' => $gift]);
        $this->actingAs($user)->post('/ar/checkout', ['billing_name' => 'P', 'billing_country' => 'SA', 'accept_terms' => '1']);
        $payment = Payment::latest('id')->firstOrFail();
        $this->post(route('dev.fake-pay.complete', $payment->gateway_ref), ['outcome' => 'pay']);
        $this->get(route('checkout.return', $payment->idempotency_key));

        return $payment->order->fresh();
    }

    public function test_paid_order_sends_one_receipt_in_the_customers_language_with_gift_code(): void
    {
        Notification::fake();
        $user = $this->customer(['locale' => 'ar']);
        $order = $this->paidOrder($user, gift: true);

        Notification::assertSentToTimes($user, OrderPaid::class, 1);
        Notification::assertSentTo($user, OrderPaid::class, function (OrderPaid $n, $channels, $notifiable, $locale) use ($order) {
            $mail = $n->toMail($notifiable);
            $text = implode("\n", $mail->introLines);

            return $locale === 'ar' && str_contains($mail->subject, $order->number)
                && str_contains($text, $order->items->first()->giftCode->code_encrypted);
        });
    }

    public function test_receipt_and_verification_emails_render_rtl_in_arabic(): void
    {
        $user = $this->customer(['locale' => 'ar']);
        $order = $this->paidOrder($user);
        app()->setLocale('ar');
        $html = (string) (new OrderPaid($order))->toMail($user)->render();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('شكرًا لك', $html);

        Notification::fake();
        $this->app['auth']->guard('web')->logout();
        $this->post('/register', ['name' => 'N', 'email' => 'v@example.test', 'password' => 'a-Strong-pass-9', 'password_confirmation' => 'a-Strong-pass-9', 'terms' => '1']);
        Notification::assertSentTo(User::where('email', 'v@example.test')->first(), VerifyEmail::class);
    }

    public function test_customer_refund_request_opens_one_billing_ticket(): void
    {
        $user = $this->customer();
        $order = $this->paidOrder($user);
        $item = $order->items()->first();

        $this->actingAs($user)->post(route('orders.refund-request', ['order' => $order, 'locale' => 'ar']), ['item' => $item->id, 'reason' => 'Bought by mistake'])
            ->assertRedirect();
        $ticket = SupportTicket::firstOrFail();
        $this->assertSame(['billing', $order->id], [$ticket->category, $ticket->order_id]);
        $this->assertStringContainsString('Bought by mistake', $ticket->messages()->first()->body);

        $this->post(route('orders.refund-request', ['order' => $order, 'locale' => 'ar']), ['item' => $item->id])->assertSessionHasErrors('refund');
        $this->assertSame(1, SupportTicket::count());
    }

    public function test_staff_price_changes_are_audited_but_customer_actions_are_not(): void
    {
        $pkg = $this->package($this->game(), 3900);
        $this->assertSame(0, AuditLog::count(), 'no staff user → nothing logged');

        $this->actingAs($this->staff('catalog_manager'));
        $pkg->prices()->first()->update(['base_amount' => 4900]);

        $log = AuditLog::where('action', 'PackagePrice.updated')->firstOrFail();
        $this->assertSame(['base_amount' => 3900], $log->changes['before']);
        $this->assertSame(['base_amount' => 4900], $log->changes['after']);
        $this->actingAs(User::role('super_admin')->first() ?? $this->staff())->get('/admin/audit-log')->assertOk();
    }
}
