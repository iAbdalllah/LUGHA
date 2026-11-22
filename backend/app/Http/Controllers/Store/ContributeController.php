<?php

namespace App\Http\Controllers\Store;

use App\Domain\Community\FeedbackService;
use App\Enums\TranslationStatus;
use App\Http\Controllers\Controller;
use App\Models\LocalizationPackage;
use App\Models\Translation;
use App\Models\TranslationFeedback;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Contributor program: apply, then suggest better wording for lines of packages you own. */
class ContributeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('store.contribute.index', [
            'user' => $user,
            'packages' => $user->isContributor()
                ? $user->activeEntitlements()->with('package.game')->get()->pluck('package')->unique('id')->values()
                : collect(),
            'mine' => $user->feedback()->with(['translation:id,source_text,target_text', 'package:id,slug,name'])->latest()->limit(30)->get(),
            'acceptedCount' => $user->feedback()->where('status', 'accepted')->count(),
        ]);
    }

    public function apply(Request $request)
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:60'],
            'accept_terms' => ['accepted'],
        ]);
        $user = $request->user();
        if (! in_array($user->contributor_status, ['approved', 'suspended'], true)) {
            $user->forceFill([
                'contributor_status' => 'applied',
                'contributor_applied_at' => now(),
                'contributor_display_name' => trim($data['display_name']),
            ])->save();
        }

        return redirect()->route('contribute.index')->with('status', __('community.applied'));
    }

    public function lines(Request $request, LocalizationPackage $package)
    {
        $user = $request->user();
        abort_unless($user->isContributor() && $user->owns($package), 403);
        $q = trim((string) $request->query('q', ''));
        $category = $request->query('category');

        $lines = FeedbackService::linesOf($package)->where('status', TranslationStatus::Published)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('source_text', 'like', '%'.$q.'%')->orWhere('target_text', 'like', '%'.$q.'%')))
            ->when($category, fn ($w) => $w->where('category_id', $category))
            ->with('category')->orderBy('id')->paginate(20)->withQueryString();

        return view('store.contribute.lines', [
            'package' => $package->load('game'),
            'lines' => $lines,
            'categories' => $package->effectiveCategories(),
            'open' => TranslationFeedback::where('user_id', $user->id)->where('status', 'open')
                ->whereIn('translation_id', $lines->pluck('id'))->pluck('translation_id')->all(),
            'q' => $q,
            'category' => $category,
        ]);
    }

    public function suggest(Request $request, LocalizationPackage $package, FeedbackService $feedback)
    {
        $data = $request->validate([
            'entry_id' => ['required', 'integer'],
            'reason' => ['required', Rule::in(TranslationFeedback::REASONS)],
            'suggested_text' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $line = Translation::findOrFail($data['entry_id']);
        $feedback->submit($request->user(), $package, $line, 'suggestion', $data + ['source' => 'store']);

        return back()->with('status', __('community.suggestion_sent'));
    }
}
