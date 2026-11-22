<?php

namespace App\Models;

use App\Enums\EntitlementSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entitlement extends Model
{
    public $timestamps = false;

    /** Mirror DB defaults so freshly created models behave like loaded ones. */
    protected $attributes = ['status' => 'active'];

    protected $fillable = [
        'user_id', 'package_id', 'source', 'order_item_id', 'parent_entitlement_id', 'update_major', 'updates_until',
        'status', 'revoked_reason', 'granted_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => EntitlementSource::class, 'granted_at' => 'datetime', 'revoked_at' => 'datetime',
            'updates_until' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(LocalizationPackage::class, 'package_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_entitlement_id');
    }

    /** Whether a given package release is covered by this grant's update terms. */
    public function covers(PackageVersion $version): bool
    {
        if ($this->status !== 'active' || $version->package_id !== $this->package_id) {
            return false;
        }
        if ($this->update_major !== null) {
            return $version->major === $this->update_major;
        }
        if ($this->updates_until !== null) {
            return $version->released_at !== null && $version->released_at->lte($this->updates_until);
        }

        return true; // lifetime
    }
}
