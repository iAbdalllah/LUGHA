<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\GameResource;
use App\Http\Resources\V1\PackageResource;
use App\Models\Game;
use App\Models\LocalizationPackage;
use App\Models\LocalizationStyle;
use App\Models\Platform;
use App\Models\TranslationCategory;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    private function packageRelations(): array
    {
        return ['prices', 'categories', 'inclusions.categories', 'style', 'game'];
    }

    public function home()
    {
        $games = fn () => Game::visible()->with(['platforms', 'publishedPackages.prices']);

        return response()->json([
            'featured' => GameResource::collection($games()->where('is_featured', true)->latest('published_at')->limit(8)->get()),
            'recently_added' => GameResource::collection($games()->latest('published_at')->limit(8)->get()),
            'platforms' => Platform::orderBy('sort_order')->get()->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->name]),
        ]);
    }

    public function games(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'platform' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', 'in:featured,new,updated,title'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $games = Game::visible()->with(['platforms', 'publishedPackages.prices'])
            ->when($data['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($w) => $w->where('title->ar', 'like', $like)->orWhere('title->en', 'like', $like)->orWhere('slug', 'like', $like));
            })
            ->when($data['platform'] ?? null, fn ($q, $p) => $q->whereHas('platforms', fn ($w) => $w->where('slug', $p)))
            ->when($data['sort'] ?? 'featured', fn ($q, $s) => match ($s) {
                'new' => $q->orderByDesc('published_at')->orderByDesc('id'),
                'updated' => $q->orderByDesc('updated_at')->orderByDesc('id'),
                'title' => $q->orderBy('slug')->orderBy('id'),
                default => $q->orderByDesc('is_featured')->orderByDesc('id'),
            })
            ->cursorPaginate($data['limit'] ?? 20)->withQueryString();

        return response()->json(['data' => GameResource::collection($games->items()), 'next_cursor' => $games->nextCursor()?->encode()]);
    }

    public function game(Game $game)
    {
        abort_unless(in_array($game->status->value, ['published', 'coming_soon'], true), 404);
        $game->load(['platforms', 'versions', 'media', 'publishedPackages' => fn ($q) => $q->with($this->packageRelations())]);

        return response()->json(['data' => new GameResource($game)]);
    }

    public function gamePackages(Game $game)
    {
        abort_unless(in_array($game->status->value, ['published', 'coming_soon'], true), 404);

        return response()->json(['data' => PackageResource::collection($game->publishedPackages()->with($this->packageRelations())->get())]);
    }

    public function package(LocalizationPackage $package)
    {
        abort_unless($package->status->value === 'published', 404);

        return response()->json(['data' => new PackageResource($package->load($this->packageRelations()))]);
    }

    public function reference()
    {
        $map = fn ($q) => $q->get()->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name]);

        return response()->json([
            'categories' => $map(TranslationCategory::orderBy('sort_order')),
            'platforms' => $map(Platform::orderBy('sort_order')),
            'styles' => $map(LocalizationStyle::query()),
        ]);
    }
}
