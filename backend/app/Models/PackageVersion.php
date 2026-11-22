<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class PackageVersion extends Model
{
    use HasTranslations;

    protected $fillable = [
        'package_id', 'version', 'major', 'game_version_min_id', 'game_version_max_id', 'min_app_version',
        'release_notes', 'entry_count', 'coverage', 'build_status', 'build_log', 'released_at', 'created_by',
        'content_key_encrypted', 'signing_key_id', 'build_report',
    ];

    public array $translatable = ['release_notes'];

    protected $attributes = ['min_app_version' => '1.0.0', 'build_status' => 'pending'];

    protected function casts(): array
    {
        return ['coverage' => 'array', 'released_at' => 'datetime', 'content_key_encrypted' => 'encrypted', 'build_report' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (PackageVersion $v) {
            $v->major = (int) explode('.', $v->version)[0];
        });
    }

    public function files(): HasMany
    {
        return $this->hasMany(PackageFile::class, 'package_version_id');
    }

    public function fullFile(): ?PackageFile
    {
        return $this->files()->where('kind', 'full')->first();
    }

    /** Raw 32-byte content key. */
    public function contentKey(): ?string
    {
        return $this->content_key_encrypted ? hex2bin($this->content_key_encrypted) : null;
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
