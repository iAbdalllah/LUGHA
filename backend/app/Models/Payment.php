<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'order_id', 'gateway', 'gateway_ref', 'idempotency_key', 'method', 'status', 'amount', 'currency',
        'checkout_url', 'raw_last_status', 'verified_at',
    ];

    /** Mirror DB defaults so freshly created models behave like loaded ones. */
    protected $attributes = ['status' => 'initiated', 'refunded_amount' => 0];

    protected function casts(): array
    {
        return ['status' => PaymentStatus::class, 'raw_last_status' => 'array', 'verified_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
