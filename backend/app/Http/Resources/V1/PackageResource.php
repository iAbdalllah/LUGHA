<?php

namespace App\Http\Resources\V1;

use App\Models\LocalizationPackage;
use App\Models\PackageUpgrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LocalizationPackage */
class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $price = $this->priceFor();
        $user = $request->user('sanctum');
        $owned = $user ? $user->owns($this->resource) : false;
        $upgrade = $user && ! $owned ? PackageUpgrade::where('to_package_id', $this->id)
            ->whereIn('from_package_id', $user->activeEntitlements()->pluck('package_id'))->orderBy('amount')->first() : null;
        $release = $this->latestRelease();

        return [
            'id' => $this->public_id,
            'slug' => $this->slug,
            'game' => $this->whenLoaded('game', fn () => ['slug' => $this->game->slug, 'title' => $this->game->title]),
            'name' => $this->name,
            'summary' => $this->summary,
            'tier_rank' => $this->tier_rank,
            'is_bundle' => $this->is_bundle,
            'locale' => $this->locale,
            'style' => $this->whenLoaded('style', fn () => ['slug' => $this->style->slug, 'name' => $this->style->name]),
            'categories' => $this->effectiveCategories()->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name])->values(),
            'includes' => $this->whenLoaded('inclusions', fn () => $this->inclusions->pluck('slug')),
            'price' => $price ? [
                'base' => Money::of($price->base_amount, $price->currency),
                'sale' => $price->saleActive() ? Money::of($price->sale_amount, $price->currency) : null,
                'sale_ends_at' => $price->saleActive() ? $price->sale_ends_at?->toIso8601String() : null,
                'current' => Money::of($price->effectiveAmount(), $price->currency),
            ] : null,
            'upgrade_offer' => $upgrade ? ['from' => $upgrade->from->slug, 'price' => Money::of($upgrade->amount, $upgrade->currency)] : null,
            'update_terms' => ['policy' => $this->update_policy->value, 'major' => $this->current_major, 'months' => $this->update_months],
            'latest_version' => $release ? ['version' => $release->version, 'released_at' => $release->released_at?->toIso8601String()] : null,
            'purchasable' => $this->isPurchasable(),
            'owned' => $owned,
        ];
    }
}
