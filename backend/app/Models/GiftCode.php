<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCode extends Model
{
    protected $fillable = [
        'code_hash', 'code_hint', 'code_encrypted', 'package_id', 'order_item_id', 'purchaser_id', 'redeemed_by', 'redeemed_at',
        'revoked_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['code_encrypted' => 'encrypted', 'redeemed_at' => 'datetime', 'revoked_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(LocalizationPackage::class, 'package_id');
    }
}
