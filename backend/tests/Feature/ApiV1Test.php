<?php

namespace Tests\Feature;

use App\Domain\Payments\Gateways\FakeGateway;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\V1\AuthController;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class ApiV1Test extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function token(string $email = 'player@lugha.test'): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password', 'device_name' => 'test'])
            ->assertOk()->json('token');
    }

    public function test_catalog_is_public_and_localized(): void
    {
        $this->getJson('/api/v1/games', ['Accept-Language' => 'en'])->assertOk()
            ->assertHeader('Content-Language', 'en')->assertJsonFragment(['title' => 'Sandline']);
        $this->getJson('/api/v1/games/sandline', ['Accept-Language' => 'ar'])->assertOk()
            ->assertJsonPath('data.title', 'خط الرمال')
            ->assertJsonPath('data.packages.0.price.current.amount', 3900)
            ->assertJsonPath('data.packages.0.price.current.currency', 'SAR');
        $this->getJson('/api/v1/packages/sandline-sa-full')->assertOk()->assertJsonPath('data.purchasable', true);
        $this->getJson('/api/v1/games?q=nothing-matches')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/home')->assertOk()->assertJsonStructure(['featured', 'recently_added', 'platforms']);
        $this->getJson('/api/v1/reference')->assertOk()->assertJsonStructure(['categories', 'platforms', 'styles']);
    }

    public function test_errors_are_problem_json(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/games/nope')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->postJson('/api/v1/auth/login', ['email' => 'player@lugha.test', 'password' => 'wrong', 'device_name' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_register_then_unverified_user_cannot_buy(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'N', 'email' => 'n@example.test', 'password' => 'a-Strong-pass-9', 'password_confirmation' => 'a-Strong-pass-9', 'terms' => true])
            ->assertCreated()->assertJsonPath('user.email_verified', false);
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'n@example.test', 'password' => 'a-Strong-pass-9', 'device_name' => 'test'])->assertOk();
        $token = $login->json('token');
        $this->withToken($token)->getJson('/api/v1/cart')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_full_purchase_over_the_api(): void
    {
        $token = $this->token();
        $api = fn () => $this->withToken($token);

        $api()->postJson('/api/v1/cart/items', ['package' => 'iron-tide-sa-story'])->assertCreated()->assertJsonPath('total.amount', 6900);
        $api()->postJson('/api/v1/cart/items', ['package' => 'last-lantern-sa-full'])->assertStatus(422)->assertJsonPath('code', 'checkout_rejected');

        $this->withToken($token)->postJson('/api/v1/orders', ['billing' => ['name' => 'P', 'country' => 'SA']])->assertStatus(400);
        $order = $api()->withHeader('Idempotency-Key', 'key-123456')->postJson('/api/v1/orders', ['billing' => ['name' => 'P', 'country' => 'SA']])
            ->assertCreated()->json('data');
        $again = $api()->withHeader('Idempotency-Key', 'key-123456')->postJson('/api/v1/orders', ['billing' => ['name' => 'P', 'country' => 'SA']])->json('data');
        $this->assertSame($order['id'], $again['id'], 'same idempotency key returns the same order');

        $pay = $api()->postJson("/api/v1/orders/{$order['id']}/pay")->assertOk()->json();
        $this->assertStringContainsString('/dev/fake-pay/', $pay['action']['url']);

        // The buyer finishes in a browser without a web session; the return URL verifies server-side.
        $payment = Payment::where('idempotency_key', $pay['payment_id'])->firstOrFail();
        FakeGateway::setOutcome($payment->gateway_ref, PaymentStatus::Paid);
        $this->get(route('checkout.return', $payment->idempotency_key))->assertRedirect();

        $api()->getJson("/api/v1/orders/{$order['id']}")->assertOk()->assertJsonPath('data.status', 'paid');
        $api()->getJson('/api/v1/library')->assertOk()->assertJsonFragment(['package' => 'iron-tide-sa-story']);
        $full = collect($api()->getJson('/api/v1/games/iron-tide/packages')->assertOk()->json('data'))->firstWhere('slug', 'iron-tide-sa-full');
        $this->assertSame(['from' => 'iron-tide-sa-story', 'amount' => 5000], ['from' => $full['upgrade_offer']['from'], 'amount' => $full['upgrade_offer']['price']['amount']]);
    }

    public function test_other_users_orders_are_hidden_and_logout_revokes_token(): void
    {
        $token = $this->token();
        $this->withToken($token)->postJson('/api/v1/cart/items', ['package' => 'sandline-sa-dialogue']);
        $id = $this->withToken($token)->withHeader('Idempotency-Key', 'abcdefgh1')->postJson('/api/v1/orders', ['billing' => ['name' => 'P', 'country' => 'SA']])->json('data.id');

        $other = User::where('email', 'admin@lugha.test')->first();
        $otherToken = $other->createToken('t', AuthController::ABILITIES)->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson("/api/v1/orders/$id")->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_token_without_commerce_ability_cannot_buy(): void
    {
        $token = User::where('email', 'player@lugha.test')->first()->createToken('reader', ['catalog:read', 'library:read'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/library')->assertOk();
        $this->withToken($token)->getJson('/api/v1/cart')->assertForbidden()->assertJsonPath('code', 'forbidden');
    }
}
