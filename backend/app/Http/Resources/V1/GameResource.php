<?php

namespace App\Http\Resources\V1;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Game */
class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $all = $request->boolean('all_locales');

        return [
            'id' => $this->public_id,
            'slug' => $this->slug,
            'title' => $all ? $this->getTranslations('title') : $this->title,
            'description' => $all ? $this->getTranslations('description') : $this->description,
            'developer' => $this->developer,
            'publisher' => $this->publisher,
            'status' => $this->status->value,
            'cover_url' => $this->coverUrl(),
            'platforms' => $this->whenLoaded('platforms', fn () => $this->platforms->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->name])),
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->pluck('version')),
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => ['url' => $m->url(), 'kind' => $m->kind, 'caption' => $m->caption])),
            'packages' => PackageResource::collection($this->whenLoaded('publishedPackages')),
            'from_price' => $this->whenLoaded('publishedPackages', fn () => Money::of($this->publishedPackages->map(fn ($p) => $p->priceFor()?->effectiveAmount())->filter()->min())),
        ];
    }
}
