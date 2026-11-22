<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\LocalizationPackage;
use App\Models\PackageUpgrade;

class PackageController extends Controller
{
    public function show(LocalizationPackage $package)
    {
        abort_unless($package->status->value === 'published' && in_array($package->game->status->value, ['published', 'coming_soon'], true), 404);
        $package->load(['game.platforms', 'prices', 'categories', 'inclusions', 'style', 'platforms',
            'versions' => fn ($q) => $q->where('build_status', 'released')->with(['minGameVersion', 'maxGameVersion'])->latest('released_at')]);

        $user = auth()->user();

        return view('store.packages.show', [
            'package' => $package,
            'categories' => $package->effectiveCategories(),
            'owned' => $user?->owns($package) ?? false,
            'upgrade' => $user ? PackageUpgrade::where('to_package_id', $package->id)
                ->whereIn('from_package_id', $user->activeEntitlements()->pluck('package_id'))->orderBy('amount')->first() : null,
        ]);
    }
}
