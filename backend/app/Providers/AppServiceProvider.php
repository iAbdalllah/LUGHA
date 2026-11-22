<?php

namespace App\Providers;

use App\Models\Coupon;
use App\Models\Game;
use App\Models\LocalizationPackage;
use App\Models\PackagePrice;
use App\Models\PackageUpgrade;
use App\Models\PackageVersion;
use App\Observers\AuditedObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // docs/03-api.md: 120/min per token (or IP), 10/min for sign-in and registration.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));

        foreach ([Game::class, LocalizationPackage::class, PackagePrice::class,
            PackageUpgrade::class, Coupon::class, PackageVersion::class] as $model) {
            $model::observe(AuditedObserver::class);
        }
    }
}
