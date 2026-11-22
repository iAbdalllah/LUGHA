<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class PackageVersion extends Model
{
    use HasTranslations;

    protected $fillable = [
        'package_id', 'version', 'major', 'game_version_min_id', 'game_version_max_id', 'min_app_version',
        'release_notes', 'entry_count', 'coverage', 'build_status', 'build_log', 'released_at', 'created_by',
    ];

    public array $translatable = ['release_notes'];

    protected function casts(): array
    {
        return ['coverage' => 'array', 'released_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (PackageVersion $v) {
            $v->major = (int) explode('.', $v->version)[0];
        });
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(LocalizationPackage::class, 'package_id');
    }

    public function minGameVersion(): BelongsTo
    {
        return $this->belongsTo(GameVersion::class, 'game_version_min_id');
    }

    public function maxGameVersion(): BelongsTo
    {
        return $this->belongsTo(GameVersion::class, 'game_version_max_id');
    }
}
