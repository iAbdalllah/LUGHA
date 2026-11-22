<?php

namespace App\Http\Controllers\Store;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\LocalizationPackage;
use App\Models\PackageVersion;
use App\Models\Platform;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function __invoke()
    {
        $base = fn () => Game::visible()->with(['platforms', 'publishedPackages.prices']);

        $popularIds = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Paid->value)->where('orders.paid_at', '>=', now()->subDays(60))
            ->groupBy('order_items.package_id')->orderByRaw('count(*) desc')->limit(6)->pluck('order_items.package_id');

        $popular = LocalizationPackage::published()->with(['game', 'prices', 'categories'])
            ->whereHas('game', fn ($q) => $q->visible())
            ->when($popularIds->isNotEmpty(), fn ($q) => $q->whereIn('id', $popularIds), fn ($q) => $q->orderByDesc('tier_rank')->limit(6))
            ->get()
            ->sortBy(fn ($p) => $popularIds->search($p->id))->values();

        return view('store.home', [
            'featured' => $base()->where('is_featured', true)->latest('published_at')->limit(8)->get(),
            'recent' => $base()->latest('published_at')->limit(4)->get(),
            'popular' => $popular,
            'updates' => PackageVersion::with('package.game')->where('build_status', 'released')
                ->whereHas('package', fn ($q) => $q->published())->latest('released_at')->limit(5)->get(),
            'platforms' => Platform::orderBy('sort_order')->get(),
        ]);
    }
}
