<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'package_id', 'kind', 'upgrade_from_package_id', 'list_amount', 'unit_amount', 'discount_amount',
        'vat_amount', 'snapshot',
    ];

    /** Mirror DB defaults so freshly created models behave like loaded ones. */
    protected $attributes = ['kind' => 'purchase', 'discount_amount' => 0, 'refunded_amount' => 0];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(LocalizationPackage::class, 'package_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function giftCode(): HasOne
    {
        return $this->hasOne(GiftCode::class);
    }

    public function refundableAmount(): int
    {
        return $this->unit_amount - $this->refunded_amount;
    }
}
