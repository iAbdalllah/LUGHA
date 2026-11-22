<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class TranslationCategory extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = ['slug', 'name', 'parent_id', 'sort_order'];

    public array $translatable = ['name'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
