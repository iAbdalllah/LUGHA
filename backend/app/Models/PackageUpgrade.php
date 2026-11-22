<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageUpgrade extends Model
{
    protected $fillable = ['from_package_id', 'to_package_id', 'currency', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(LocalizationPackage::class, 'from_package_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(LocalizationPackage::class, 'to_package_id');
    }
}
