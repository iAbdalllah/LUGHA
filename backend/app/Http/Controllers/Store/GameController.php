<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\PackageUpgrade;
use App\Models\Platform;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'platform' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', 'in:featured,new,updated,title'],
        ]);

        $games = Game::visible()->with(['platforms', 'publishedPackages.prices'])
            ->when($data['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($w) => $w->where('title->ar', 'like', $like)->orWhere('title->en', 'like', $like)->orWhere('slug', 'like', $like));
            })
            ->when($data['platform'] ?? null, fn ($q, $p) => $q->whereHas('platforms', fn ($w) => $w->where('slug', $p)))
            ->when(($data['sort'] ?? 'featured'), function ($q, $sort) {
                match ($sort) {
                    'new' => $q->latest('published_at'),
                    'updated' => $q->latest('updated_at'),
                    'title' => $q->orderBy('slug'),
                    default => $q->orderByDesc('is_featured')->latest('published_at'),
                };
            })
            ->paginate(24)->withQueryString();

        return view('store.games.index', ['games' => $games, 'platforms' => Platform::orderBy('sort_order')->get(), 'filters' => $data]);
    }

    public function show(Game $game)
    {
        abort_unless(in_array($game->status->value, ['published', 'coming_soon'], true), 404);

        $game->load(['platforms', 'versions', 'media', 'publishedPackages' => fn ($q) => $q->with(['prices', 'categories', 'inclusions.categories', 'style', 'versions'])]);
        $packages = $game->publishedPackages;
        $user = auth()->user();
        $owned = $user ? $user->activeEntitlements()->pluck('package_id')->all() : [];
        $inCart = $user ? $user->cartItems()->where('as_gift', false)->pluck('package_id')->all() : [];

        // Comparison matrix: every category any package delivers, against each package.
        $columns = $packages->mapWithKeys(fn ($p) => [$p->id => $p->effectiveCategories()->pluck('id')->all()]);
        $categories = $packages->flatMap(fn ($p) => $p->effectiveCategories())->unique('id')->sortBy('sort_order')->values();

        $upgrades = $user ? PackageUpgrade::whereIn('from_package_id', $owned)
            ->whereIn('to_package_id', $packages->pluck('id'))->get()->groupBy('to_package_id')->map->min('amount') : collect();

        $lastUpdate = $packages->flatMap->versions->where('build_status', 'released')->max('released_at');

        return view('store.games.show', compact('game', 'packages', 'owned', 'inCart', 'columns', 'categories', 'upgrades', 'lastUpdate'));
    }
}
