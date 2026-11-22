<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasUlids;

    public const KINDS = ['macos', 'windows', 'android', 'ios', 'android_tv'];

    protected $fillable = ['user_id', 'kind', 'name', 'model', 'os_version', 'app_version', 'public_key', 'key_fingerprint', 'status', 'token_id', 'last_seen_at', 'removed_at'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Device-limit group: desktops and phones count together, TVs separately. */
    public static function limitGroup(string $kind): string
    {
        return $kind === 'android_tv' ? 'tv' : 'app';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }

    public function publicKeyBytes(): string
    {
        return base64_decode($this->public_key, true) ?: '';
    }
}
