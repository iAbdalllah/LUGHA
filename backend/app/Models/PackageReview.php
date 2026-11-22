<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Verified-owner review of a localization package. */
class PackageReview extends Model
{
    protected $fillable = ['package_id', 'user_id', 'rating', 'title', 'body', 'locale', 'package_version', 'status', 'hidden_reason', 'moderated_by'];

    protected $attributes = ['status' => 'published'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('status', 'published');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(LocalizationPackage::class, 'package_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }
}
