<?php

namespace App\Models;

use App\Enums\GameStatus;
use App\Enums\RightsStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

class Game extends Model
{
    use HasFactory, HasTranslations, HasUlids, SoftDeletes;

    protected $fillable = [
        'slug', 'title', 'description', 'developer', 'publisher', 'release_date', 'cover_path', 'hero_path',
        'rights_status', 'rights_notes', 'status', 'is_featured', 'published_at',
    ];

    public array $translatable = ['title', 'description'];

    /** Mirror DB defaults so freshly created models behave like loaded ones. */
    protected $attributes = ['status' => 'draft', 'rights_status' => 'pending', 'is_featured' => false];

    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'rights_status' => RightsStatus::class,
            'is_featured' => 'boolean',
            'release_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Games visible in the store. */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereIn('status', [GameStatus::Published, GameStatus::ComingSoon]);
    }

    public function platforms(): BelongsToMany
    {
        return $this->belongsToMany(Platform::class)->orderBy('sort_order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GameVersion::class)->orderBy('id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(GameMedia::class)->orderBy('sort_order');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(LocalizationPackage::class)->orderBy('sort_order')->orderBy('tier_rank');
    }

    public function publishedPackages(): HasMany
    {
        return $this->packages()->where('status', 'published');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null;
    }

    public function isSellable(): bool
    {
        return $this->status === GameStatus::Published && $this->rights_status->allowsSelling();
    }
}
