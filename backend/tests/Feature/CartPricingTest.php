<?php

namespace Tests\Feature;

use App\Domain\Commerce\CartPricer;
use App\Domain\Entitlements\EntitlementService;
use App\Enums\EntitlementSource;
use App\Enums\RightsStatus;
use App\Models\Coupon;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class CartPricingTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    private function quote($user, array $packages, ?Coupon $coupon = null)
    {
        return app(CartPricer::class)->quote($user, collect($packages)->map(fn ($p) => ['package' => $p, 'as_gift' => false]), $coupon);
    }

    public function test_vat_is_extracted_from_inclusive_price(): void
    {
        $this->assertSame(1500, Money::vatIncluded(11500));
        $this->assertSame(509, Money::vatIncluded(3900));
    }

    public function test_quote_uses_server_prices_and_active_sales(): void
    {
        $game = $this->game();
        $a = $this->package($game, 3900);
        $b = $this->package($game, 11900);
        $b->prices()->first()->update(['sale_amount' => 9900, 'sale_ends_at' => now()->addDay()]);

        $q = $this->quote($this->customer(), [$a->fresh(), $b->fresh()]);

        $this->assertSame(3900 + 9900, $q->total());
        $this->assertTrue($q->isCheckoutable());
    }

    public function test_expired_sale_is_ignored(): void
    {
        $p = $this->package($this->game(), 5000);
        $p->prices()->first()->update(['sale_amount' => 1000, 'sale_ends_at' => now()->subMinute()]);

        $this->assertSame(5000, $this->quote($this->customer(), [$p->fresh()])->total());
    }

    public function test_owner_of_lower_tier_gets_upgrade_price(): void
    {
        $game = $this->game();
        $dialogue = $this->package($game, 3900);
        $full = $this->package($game, 11900, ['dialogues', 'menus']);
        $this->upgrade($dialogue, $full, 8000);
        $user = $this->customer();
        app(EntitlementService::class)->grant($user, $dialogue, EntitlementSource::Purchase);

        $line = $this->quote($user, [$full])->lines[0];

        $this->assertSame('upgrade', $line->kind);
        $this->assertSame(8000, $line->net());
        $this->assertSame(11900, $line->listAmount);
    }

    public function test_already_owned_package_blocks_checkout(): void
    {
        $p = $this->package($this->game(), 3900);
        $user = $this->customer();
        app(EntitlementService::class)->grant($user, $p, EntitlementSource::Purchase);

        $q = $this->quote($user, [$p]);
        $this->assertFalse($q->isCheckoutable());
    }

    public function test_package_of_game_without_cleared_rights_is_not_purchasable(): void
    {
        $game = $this->game();
        $p = $this->package($game, 3900);
        $game->update(['rights_status' => RightsStatus::Pending]);

        $this->assertFalse($this->quote($this->customer(), [$p->fresh()])->isCheckoutable());
    }

    public function test_percent_and_fixed_coupons(): void
    {
        $game = $this->game();
        $a = $this->package($game, 4000);
        $b = $this->package($game, 6000);
        $user = $this->customer();

        $pct = Coupon::create(['code' => 'ten', 'kind' => 'percent', 'value' => 10]);
        $this->assertSame(1000, $this->quote($user, [$a, $b], $pct)->discount());

        $fixed = Coupon::create(['code' => 'twenty', 'kind' => 'fixed', 'value' => 2000]);
        $q = $this->quote($user, [$a, $b], $fixed);
        $this->assertSame(2000, $q->discount());
        $this->assertSame(8000, $q->total());
        $this->assertSame(800, $q->lines[0]->discount); // proportional share
    }

    public function test_invalid_coupons_are_rejected_with_reason(): void
    {
        $p = $this->package($this->game(), 4000);
        $user = $this->customer();

        $expired = Coupon::create(['code' => 'old', 'kind' => 'percent', 'value' => 10, 'ends_at' => now()->subDay()]);
        $this->assertSame(__('cart.coupon_expired'), $this->quote($user, [$p], $expired)->couponError);

        $minimum = Coupon::create(['code' => 'big', 'kind' => 'percent', 'value' => 10, 'min_order_amount' => 10000]);
        $this->assertSame(__('cart.coupon_minimum'), $this->quote($user, [$p], $minimum)->couponError);

        $scoped = Coupon::create(['code' => 'other', 'kind' => 'percent', 'value' => 10, 'game_ids' => [999]]);
        $this->assertSame(__('cart.coupon_not_applicable'), $this->quote($user, [$p], $scoped)->couponError);
        $this->assertSame(0, $this->quote($user, [$p], $scoped)->discount());
    }
}
