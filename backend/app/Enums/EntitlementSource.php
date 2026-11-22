<?php

namespace App\Enums;

enum EntitlementSource: string
{
    case Purchase = 'purchase';
    case Upgrade = 'upgrade';
    case Bundle = 'bundle';
    case Gift = 'gift';
    case Admin = 'admin';
    case Promo = 'promo';
}
