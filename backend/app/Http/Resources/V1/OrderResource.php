<?php

namespace App\Http\Resources\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'subtotal' => Money::of($this->subtotal_amount, $this->currency),
            'discount' => Money::of($this->discount_amount, $this->currency),
            'vat' => Money::of($this->vat_amount, $this->currency),
            'total' => Money::of($this->total_amount, $this->currency),
            'refunded' => Money::of($this->refunded_amount, $this->currency),
            'invoice_number' => $this->invoice_number,
            'invoice_url' => $this->invoice_number ? route('orders.show', ['order' => $this->resource, 'locale' => app()->getLocale()]) : null,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'package' => $i->package->slug,
                'name' => $i->package->name,
                'kind' => $i->kind,
                'amount' => Money::of($i->unit_amount, $this->currency),
                'gift_code' => $i->kind === 'gift' && $this->number ? $i->giftCode?->code_encrypted : null,
            ])),
            'created_at' => $this->created_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
