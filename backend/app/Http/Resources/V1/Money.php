<?php

namespace App\Http\Resources\V1;

use App\Support\Money as M;

final class Money
{
    public static function of(?int $amount, string $currency = 'SAR'): ?array
    {
        return $amount === null ? null : ['amount' => $amount, 'currency' => $currency, 'formatted' => M::format($amount, $currency)];
    }
}
