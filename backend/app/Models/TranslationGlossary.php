<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranslationGlossary extends Model
{
    protected $fillable = ['game_id', 'locale', 'term', 'translation', 'do_not_translate', 'notes'];

    protected function casts(): array
    {
        return ['do_not_translate' => 'boolean'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
