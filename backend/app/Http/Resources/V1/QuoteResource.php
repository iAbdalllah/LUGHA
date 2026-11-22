<?php

namespace App\Http\Resources\V1;

use App\Domain\Commerce\Quote;
use App\Domain\Commerce\QuoteLine;

final class QuoteResource
{
    public static function make(Quote $q): array
    {
        return [
            'lines' => array_map(fn (QuoteLine $l) => [
                'package' => $l->package->slug,
                'name' => $l->package->name,
                'kind' => $l->kind,
                'list_price' => Money::of($l->listAmount, $q->currency),
                'discount' => Money::of($l->discount, $q->currency),
                'amount' => Money::of($l->net(), $q->currency),
                'error' => $l->error,
            ], $q->lines),
            'coupon' => $q->coupon?->code,
            'coupon_error' => $q->couponError,
            'subtotal' => Money::of($q->subtotal(), $q->currency),
            'discount' => Money::of($q->discount(), $q->currency),
            'vat' => Money::of($q->vat(), $q->currency),
            'total' => Money::of($q->total(), $q->currency),
            'checkoutable' => $q->isCheckoutable(),
        ];
    }
}
