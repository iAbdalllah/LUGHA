<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Community\FeedbackService;
use App\Domain\Localization\GameMarkup;
use App\Domain\Reviews\ReviewService;
use App\Http\Controllers\Controller;
use App\Models\LocalizationPackage;
use App\Models\PackageReview;
use App\Models\Translation;
use App\Models\TranslationFeedback;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Report-a-line from the apps, suggestions from contributors, and package reviews. */
class CommunityController extends Controller
{
    /** POST /translation-reports — entry_id is the line id carried in the package (`Hit.entry_id`). */
    public function report(Request $request, FeedbackService $feedback)
    {
        $data = $request->validate([
            'package' => ['required', 'string'],
            'version' => ['nullable', 'string', 'max:32'],
            'entry_id' => ['required', 'integer'],
            'kind' => ['sometimes', Rule::in(TranslationFeedback::KINDS)],
            'reason' => ['required', Rule::in(TranslationFeedback::REASONS)],
            'suggested_text' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', Rule::in(TranslationFeedback::SOURCES)],
        ]);
        // Apps know the package by its id in the manifest (= slug).
        $package = LocalizationPackage::where('slug', $data['package'])->orWhere('public_id', $data['package'])->firstOrFail();
        $line = Translation::findOrFail(GameMarkup::lineId((int) $data['entry_id'])); // a timed segment reports its line
        $fb = $feedback->submit($request->user(), $package, $line, $data['kind'] ?? 'report', $data);

        return response()->json(['id' => $fb->id, 'status' => $fb->status], 202);
    }

    public function myFeedback(Request $request)
    {
        return response()->json(['data' => $request->user()->feedback()->latest()->limit(50)->get()
            ->map(fn (TranslationFeedback $f) => [
                'id' => $f->id, 'kind' => $f->kind, 'reason' => $f->reason, 'status' => $f->status,
                'entry_id' => $f->translation_id, 'suggested_text' => $f->suggested_text, 'applied_text' => $f->applied_text,
                'created_at' => $f->created_at->toIso8601String(), 'resolved_at' => $f->resolved_at?->toIso8601String(),
            ])]);
    }

    public function reviews(LocalizationPackage $package)
    {
        $page = $package->reviews()->visible()->with('user:id,name,contributor_display_name')->latest()->cursorPaginate(20);

        return response()->json([
            'summary' => ['average' => $package->rating_avg, 'count' => $package->rating_count, 'distribution' => ReviewService::distribution($package),
                'qa_score' => $package->qa_score, 'qa' => $package->qa_breakdown],
            'data' => collect($page->items())->map(fn (PackageReview $r) => [
                'id' => $r->id, 'rating' => $r->rating, 'title' => $r->title, 'body' => $r->body, 'author' => $r->user->publicName(),
                'version' => $r->package_version, 'locale' => $r->locale, 'created_at' => $r->created_at->toIso8601String(),
            ]),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function saveReview(Request $request, LocalizationPackage $package, ReviewService $reviews)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);
        $r = $reviews->save($request->user(), $package, $data + ['locale' => app()->getLocale()]);

        return response()->json(['id' => $r->id, 'status' => $r->status], $r->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteReview(Request $request, LocalizationPackage $package, ReviewService $reviews)
    {
        $reviews->delete($request->user(), $package);

        return response()->noContent();
    }
}
