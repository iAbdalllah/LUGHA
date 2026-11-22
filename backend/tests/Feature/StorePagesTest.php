<?php

namespace Tests\Feature;

use App\Models\LocalizationPackage;
use App\Models\Translation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class StorePagesTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_public_pages_render_in_both_directions(): void
    {
        $this->get('/')->assertRedirect('/ar');
        $this->get('/ar')->assertOk()->assertSee('dir="rtl"', false)->assertSee('العب بلغتك.');
        $this->get('/en')->assertOk()->assertSee('dir="ltr"', false)->assertSee('Play in your language.');
        $this->get('/ar/games')->assertOk()->assertSee('خط الرمال');
        $this->get('/en/games?q=sand')->assertOk()->assertSee('Sandline')->assertDontSee('Iron Tide');
        $this->get('/ar/games?q='.urlencode('المدّ'))->assertOk()->assertSee('المدّ الحديدي');
        $this->get('/ar/games/sandline')->assertOk()->assertSee('مقارنة المحتوى')->assertSee('باقة القصة');
        $this->get('/en/packages/sandline-sa-full')->assertOk()->assertSee('Full Localization');
        $this->get('/ar/support')->assertOk();
        $this->get('/xx/games')->assertNotFound();
    }

    public function test_language_switch_keeps_the_page(): void
    {
        $this->get('/ar/games/sandline')->assertSee('href="'.url('/en/games/sandline').'"', false);
    }

    public function test_coming_soon_game_without_rights_cannot_be_bought(): void
    {
        $this->get('/ar/games/last-lantern')->assertOk()->assertDontSee(__('Add to cart'));
    }

    public function test_account_pages_need_sign_in(): void
    {
        foreach (['/ar/library', '/ar/cart', '/ar/orders', '/ar/checkout'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $user = $this->customer();
        foreach (['/ar/library', '/ar/cart', '/ar/orders', '/ar/downloads', '/ar/gift', '/ar/profile', '/en/library'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_auth_pages_render(): void
    {
        foreach (['/login', '/register', '/forgot-password'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_registration_creates_unverified_account(): void
    {
        $this->post('/register', ['name' => 'New', 'email' => 'new@example.test', 'password' => 'a-Strong-pass-9', 'password_confirmation' => 'a-Strong-pass-9', 'terms' => '1'])
            ->assertRedirect();
        $this->assertNull(User::where('email', 'new@example.test')->first()->email_verified_at);
    }

    public function test_admin_is_staff_only(): void
    {
        $this->actingAs($this->customer())->get('/admin')->assertForbidden();

        $admin = User::where('email', 'admin@lugha.test')->first();
        foreach (['/admin', '/admin/games', '/admin/packages', '/admin/translations', '/admin/glossary', '/admin/orders', '/admin/coupons', '/admin/users', '/admin/support-tickets', '/admin/platforms', '/admin/translation-categories', '/admin/localization-styles', '/admin/games/create', '/admin/packages/create'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $pkg = LocalizationPackage::first();
        $this->actingAs($admin)->get("/admin/packages/{$pkg->slug}/edit")->assertOk();
        $this->actingAs($admin)->get('/admin/translations/'.Translation::first()->id.'/edit')->assertOk();
    }

    public function test_translator_cannot_see_orders(): void
    {
        $translator = $this->staff('translator');
        $this->actingAs($translator)->get('/admin/translations')->assertOk();
        $this->actingAs($translator)->get('/admin/orders')->assertForbidden();
    }
}
