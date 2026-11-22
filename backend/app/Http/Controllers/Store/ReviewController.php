<?php

namespace App\Http\Controllers\Store;

use App\Domain\Reviews\ReviewService;
use App\Http\Controllers\Controller;
use App\Models\LocalizationPackage;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, LocalizationPackage $package, ReviewService $reviews)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);
        $reviews->save($request->user(), $package, $data + ['locale' => app()->getLocale()]);

        return redirect()->to(route('packages.show', $package).'#reviews')->with('status', __('reviews.saved'));
    }

    public function destroy(Request $request, LocalizationPackage $package, ReviewService $reviews)
    {
        $reviews->delete($request->user(), $package);

        return redirect()->to(route('packages.show', $package).'#reviews')->with('status', __('reviews.deleted'));
    }
}
