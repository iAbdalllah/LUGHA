<?php

namespace App\Enums;

enum UpdatePolicy: string
{
    case VersionLine = 'version_line';
    case Duration = 'duration';
    case Lifetime = 'lifetime';

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => __('status.update_policy.'.$c->value)])->all();
    }
}
