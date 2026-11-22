<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Order extends Model
{
    use HasUlids;

    /** Mirror DB defaults so freshly created models behave like loaded ones. */
    protected $attributes = ['status' => 'pending', 'currency' => 'SAR', 'discount_amount' => 0, 'refunded_amount' => 0];

    protected $fillable = [
        'user_id', 'status', 'currency', 'subtotal_amount', 'discount_amount', 'vat_amount', 'total_amount',
        'coupon_id', 'billing',
    ];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class, 'billing' => 'array', 'paid_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('id');
    }

    public function refunds(): HasManyThrough
    {
        return $this->hasManyThrough(Refund::class, Payment::class)->where('refunds.status', 'processed')->orderBy('refunds.id');
    }

    /** B2B buyers who give a VAT number get a (standard) tax invoice; everyone else a simplified one. */
    public function isB2B(): bool
    {
        return filled($this->billing['vat_number'] ?? null);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function isPaidOrRefunded(): bool
    {
        return in_array($this->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded], true);
    }
}
