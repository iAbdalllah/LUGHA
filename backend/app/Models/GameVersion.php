<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameVersion extends Model
{
    protected $fillable = ['game_id', 'version', 'released_at', 'notes'];

    protected function casts(): array
    {
        return ['released_at' => 'date'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
