<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

class GameMedia extends Model
{
    use HasTranslations;

    protected $fillable = ['game_id', 'kind', 'path', 'caption', 'sort_order'];

    public array $translatable = ['caption'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
