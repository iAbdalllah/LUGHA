<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'kind', 'value', 'min_order_amount', 'max_redemptions', 'per_user_limit', 'game_ids', 'package_ids',
        'starts_at', 'ends_at', 'is_active',
    ];

    /** Mirror DB defaults so freshly created models behave like loaded ones. */
    protected $attributes = ['is_active' => true, 'per_user_limit' => 1, 'redeemed_count' => 0];

    protected function casts(): array
    {
        return [
            'game_ids' => 'array', 'package_ids' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'is_active' => 'boolean', 'value' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (Coupon $c) => $c->code = strtoupper(trim($c->code)));
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function appliesTo(LocalizationPackage $package): bool
    {
        if ($this->package_ids && ! in_array($package->id, array_map('intval', $this->package_ids), true)) {
            return false;
        }
        if ($this->game_ids && ! in_array($package->game_id, array_map('intval', $this->game_ids), true)) {
            return false;
        }

        return true;
    }
}
