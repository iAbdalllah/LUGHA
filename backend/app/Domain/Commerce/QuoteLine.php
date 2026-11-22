<?php

namespace App\Domain\Commerce;

use App\Models\LocalizationPackage;

final class QuoteLine
{
    public int $discount = 0;

    public function __construct(
        public readonly LocalizationPackage $package,
        public readonly string $kind,            // purchase | upgrade | gift
        public readonly int $listAmount,         // current shelf price
        public readonly int $unitAmount,         // price before coupon (upgrade price for upgrades)
        public readonly ?int $upgradeFromId = null,
        public readonly ?string $error = null,   // blocking problem with this line
    ) {}

    public function net(): int
    {
        return $this->unitAmount - $this->discount;
    }
}
