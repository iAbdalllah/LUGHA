<?php

namespace App\Models;

use App\Domain\Localization\TextNormalizer;
use App\Enums\TranslationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Translation extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id', 'category_id', 'style_id', 'locale', 'string_key', 'source_text', 'target_text', 'speaker',
        'context', 'sequence_no', 'scene_ref', 'min_game_version_id', 'max_game_version_id', 'status',
        'translator_id', 'reviewer_id', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['status' => TranslationStatus::class, 'reviewed_at' => 'datetime'];
    }

    protected $attributes = ['revision' => 1, 'status' => 'draft', 'locale' => 'ar-SA'];

    /** Optional note stored with the next revision (not a column). */
    public ?string $revisionComment = null;

    protected static function booted(): void
    {
        // source_hash is always derived, never accepted from input — it is the package engine's lookup key.
        static::saving(function (Translation $t) {
            if ($t->isDirty('source_text') || ! $t->source_hash) {
                $t->source_hash = TextNormalizer::hash($t->source_text);
            }
        });

        static::updating(function (Translation $t) {
            if ($t->isDirty(['target_text', 'status'])) {
                $t->revision = $t->getOriginal('revision') + 1;
            }
        });

        // Append-only history: every change of wording or status becomes a revision row.
        static::saved(function (Translation $t) {
            if ($t->wasRecentlyCreated || $t->wasChanged(['target_text', 'status'])) {
                $t->revisions()->create([
                    'revision' => $t->revision,
                    'target_text' => $t->target_text,
                    'status' => $t->status->value,
                    'changed_by' => auth()->id(),
                    'comment' => $t->revisionComment,
                ]);
                $t->revisionComment = null;
            }
        });
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TranslationCategory::class, 'category_id');
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(LocalizationStyle::class, 'style_id');
    }

    public function translator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'translator_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(TranslationRevision::class)->orderByDesc('revision');
    }
}
