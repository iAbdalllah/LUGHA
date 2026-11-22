<?php

namespace App\Http\Controllers\Store;

use App\Domain\Analytics\Tracker;
use App\Domain\Reviews\ReviewService;
use App\Http\Controllers\Controller;
use App\Models\LocalizationPackage;
use App\Models\PackageUpgrade;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function show(Request $request, LocalizationPackage $package, Tracker $tracker)
    {
        abort_unless($package->status->value === 'published' && in_array($package->game->status->value, ['published', 'coming_soon'], true), 404);
        $package->load(['game.platforms', 'prices', 'categories', 'inclusions', 'style', 'platforms',
            'versions' => fn ($q) => $q->where('build_status', 'released')->with(['minGameVersion', 'maxGameVersion'])->latest('released_at')]);

        $user = auth()->user();
        $tracker->record('package_view', $request, $package);
        $owned = $user?->owns($package) ?? false;

        return view('store.packages.show', [
            'package' => $package,
            'categories' => $package->effectiveCategories(),
            'owned' => $owned,
            'reviews' => $package->reviews()->visible()->with('user:id,name,contributor_display_name')->latest()->paginate(8)->withQueryString(),
            'myReview' => $user ? $package->reviews()->where('user_id', $user->id)->first() : null,
            'distribution' => ReviewService::distribution($package),
            'upgrade' => $user ? PackageUpgrade::where('to_package_id', $package->id)
                ->whereIn('from_package_id', $user->activeEntitlements()->pluck('package_id'))->orderBy('amount')->first() : null,
        ]);
    }
}
