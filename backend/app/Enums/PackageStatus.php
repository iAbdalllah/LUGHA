<?php

namespace App\Enums;

enum PackageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => __('status.package.'.$c->value)])->all();
    }
}
