<?php

namespace App\Support;

use Illuminate\Support\Number;

/** Integer minor units (halalas). All store prices are VAT-inclusive. */
final class Money
{
    public static function vatRateBps(): int
    {
        return (int) config('lugha.vat_rate_bps', 1500); // 15.00%
    }

    /** VAT contained in a VAT-inclusive amount, rounded half-up to the halala. */
    public static function vatIncluded(int $gross): int
    {
        $bps = self::vatRateBps();

        return (int) round($gross * $bps / (10000 + $bps));
    }

    public static function format(int $minor, string $currency = 'SAR', ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $amount = Number::format($minor / 100, precision: 2, locale: $locale === 'ar' ? 'ar-SA-u-nu-latn' : 'en');

        return $locale === 'ar'
            ? $amount.' '.($currency === 'SAR' ? 'ر.س' : $currency)
            : $currency.' '.$amount;
    }
}
