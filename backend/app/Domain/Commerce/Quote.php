<?php

namespace App\Domain\Commerce;

use App\Models\Coupon;
use App\Support\Money;

final class Quote
{
    /** @param QuoteLine[] $lines */
    public function __construct(
        public readonly array $lines,
        public readonly string $currency,
        public readonly ?Coupon $coupon = null,
        public readonly ?string $couponError = null,
    ) {}

    public function subtotal(): int
    {
        return array_sum(array_map(fn (QuoteLine $l) => $l->unitAmount, $this->lines));
    }

    public function discount(): int
    {
        return array_sum(array_map(fn (QuoteLine $l) => $l->discount, $this->lines));
    }

    public function total(): int
    {
        return $this->subtotal() - $this->discount();
    }

    public function vat(): int
    {
        return array_sum(array_map(fn (QuoteLine $l) => Money::vatIncluded($l->net()), $this->lines));
    }

    public function errors(): array
    {
        return array_values(array_filter(array_map(fn (QuoteLine $l) => $l->error, $this->lines)));
    }

    public function isCheckoutable(): bool
    {
        return $this->lines !== [] && $this->errors() === [];
    }
}
