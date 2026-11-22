<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A player's report about a line, or an approved contributor's suggested wording. */
class TranslationFeedback extends Model
{
    public const KINDS = ['report', 'suggestion'];

    public const REASONS = ['wrong_meaning', 'typo', 'awkward', 'offensive', 'wrong_speaker', 'other'];

    public const SOURCES = ['store', 'macos', 'windows', 'android', 'ios', 'tv'];

    protected $table = 'translation_feedback';

    protected $fillable = [
        'translation_id', 'package_id', 'package_version_id', 'user_id', 'kind', 'reason', 'suggested_text', 'note',
        'source', 'status', 'resolved_by', 'resolved_at', 'resolution_note', 'applied_text',
    ];

    protected $attributes = ['status' => 'open', 'source' => 'store'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(LocalizationPackage::class, 'package_id');
    }

    public function packageVersion(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
