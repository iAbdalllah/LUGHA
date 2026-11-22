<?php

namespace App\Enums;

enum GameStatus: string
{
    case Draft = 'draft';
    case ComingSoon = 'coming_soon';
    case Published = 'published';
    case Archived = 'archived';

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => __('status.game.'.$c->value)])->all();
    }
}
