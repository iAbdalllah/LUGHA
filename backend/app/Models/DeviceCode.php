<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCode extends Model
{
    protected $fillable = ['device_code_hash', 'user_code', 'client_name', 'user_id', 'status', 'expires_at', 'last_polled_at'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_polled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
