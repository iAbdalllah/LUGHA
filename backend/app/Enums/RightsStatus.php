<?php

namespace App\Enums;

enum RightsStatus: string
{
    case Pending = 'pending';
    case Licensed = 'licensed';
    case Permitted = 'permitted';
    case Rejected = 'rejected';

    /** Packages for a game may only be sold once its rights are cleared. */
    public function allowsSelling(): bool
    {
        return in_array($this, [self::Licensed, self::Permitted], true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => __('status.rights.'.$c->value)])->all();
    }
}
