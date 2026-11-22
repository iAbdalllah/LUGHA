<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranslationRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['translation_id', 'revision', 'target_text', 'status', 'changed_by', 'comment'];

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
