<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PackagePrice extends Model
{
    protected $fillable = ['package_id', 'currency', 'base_amount', 'sale_amount', 'sale_starts_at', 'sale_ends_at'];

    protected function casts(): array
    {
        return ['sale_starts_at' => 'datetime', 'sale_ends_at' => 'datetime', 'base_amount' => 'integer', 'sale_amount' => 'integer'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(LocalizationPackage::class, 'package_id');
    }

    public function saleActive(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->sale_amount !== null
            && $this->sale_amount < $this->base_amount
            && ($this->sale_starts_at === null || $this->sale_starts_at->lte($at))
            && ($this->sale_ends_at === null || $this->sale_ends_at->gt($at));
    }

    /** Current VAT-inclusive price in minor units. */
    public function effectiveAmount(?Carbon $at = null): int
    {
        return $this->saleActive($at) ? $this->sale_amount : $this->base_amount;
    }
}
