<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = ['user_id', 'package_id', 'as_gift'];

    protected function casts(): array
    {
        return ['as_gift' => 'boolean'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(LocalizationPackage::class, 'package_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
