<?php

namespace App\Models;

use App\Enums\PackageStatus;
use App\Enums\UpdatePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Translatable\HasTranslations;

class LocalizationPackage extends Model
{
    use HasFactory, HasTranslations, HasUlids;

    protected $fillable = [
        'game_id', 'slug', 'name', 'summary', 'tier_rank', 'locale', 'style_id', 'update_policy', 'update_months',
        'current_major', 'status', 'is_bundle', 'sort_order', 'published_at',
        'rating_avg', 'rating_count', 'qa_score', 'qa_breakdown', 'qa_computed_at',
    ];

    public array $translatable = ['name', 'summary'];

    /** Mirror DB defaults so freshly created models behave like loaded ones. */
    protected $attributes = ['tier_rank' => 1, 'locale' => 'ar-SA', 'update_policy' => 'version_line', 'current_major' => 1, 'status' => 'draft', 'is_bundle' => false, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'status' => PackageStatus::class,
            'update_policy' => UpdatePolicy::class,
            'is_bundle' => 'boolean',
            'published_at' => 'datetime',
            'rating_avg' => 'float',
            'qa_breakdown' => 'array',
            'qa_computed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function booted(): void
    {
        // Rights gate (README "the risk to resolve"): nothing is sold for a game whose rights aren't cleared.
        static::saving(function (LocalizationPackage $p) {
            if ($p->status === PackageStatus::Published) {
                $game = $p->game()->first();
                if (! $game || ! $game->rights_status->allowsSelling()) {
                    throw ValidationException::withMessages([
                        'status' => __('A package can only be published when the game\'s rights are licensed or permitted.'),
                    ]);
                }
                $p->published_at ??= now();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', PackageStatus::Published);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(LocalizationStyle::class, 'style_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(TranslationCategory::class, 'package_category', 'package_id', 'category_id')
            ->orderBy('sort_order');
    }

    /** Packages a bundle contains. */
    public function inclusions(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'package_inclusions', 'bundle_id', 'included_id');
    }

    public function platforms(): BelongsToMany
    {
        return $this->belongsToMany(Platform::class, 'package_platform', 'package_id', 'platform_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PackagePrice::class, 'package_id');
    }

    /** Upgrade offers whose target is this package. */
    public function upgradeOffers(): HasMany
    {
        return $this->hasMany(PackageUpgrade::class, 'to_package_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PackageVersion::class, 'package_id')->orderByDesc('id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PackageReview::class, 'package_id');
    }

    public function latestRelease(): ?PackageVersion
    {
        return $this->versions()->where('build_status', 'released')->orderByDesc('released_at')->first();
    }

    public function priceFor(string $currency = 'SAR'): ?PackagePrice
    {
        return $this->relationLoaded('prices')
            ? $this->prices->firstWhere('currency', $currency)
            : $this->prices()->where('currency', $currency)->first();
    }

    /** Every content category this package delivers, including its bundled packages'. */
    public function effectiveCategories(): Collection
    {
        $cats = $this->categories()->get();
        if ($this->is_bundle) {
            foreach ($this->inclusions()->with('categories')->get() as $child) {
                $cats = $cats->merge($child->categories);
            }
        }

        return $cats->unique('id')->sortBy('sort_order')->values();
    }

    public function isPurchasable(string $currency = 'SAR'): bool
    {
        return $this->status === PackageStatus::Published
            && $this->game?->isSellable()
            && $this->priceFor($currency) !== null;
    }
}
