<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Platform extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = ['slug', 'name', 'sort_order'];

    public array $translatable = ['name'];
}
