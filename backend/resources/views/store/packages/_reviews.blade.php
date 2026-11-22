{{-- $package, $reviews (paginator), $myReview, $distribution, $owned --}}
<section id="reviews" class="scroll-mt-24">
    <h2 class="mb-3 text-xl font-semibold">{{ __('Player reviews') }}</h2>
    @if (session('status') && str_contains(url()->previous(), 'packages'))<p role="status" class="mb-3 text-success">{{ session('status') }}</p>@endif
    <div class="card flex flex-wrap gap-8 p-5">
        <div class="text-center">
            <div class="text-4xl font-bold">{{ $package->rating_count ? number_format($package->rating_avg, 1) : '—' }}</div>
            <div class="text-accent" aria-hidden="true">{{ str_repeat('★', (int) round($package->rating_avg ?? 0)) }}<span class="text-line">{{ str_repeat('★', 5 - (int) round($package->rating_avg ?? 0)) }}</span></div>
            <div class="text-sm text-muted">{{ trans_choice('reviews.count', $package->rating_count, ['n' => $package->rating_count]) }}</div>
        </div>
        <dl class="min-w-[200px] flex-1 space-y-1 text-sm">
            @foreach ($distribution as $stars => $n)
                <div class="flex items-center gap-2"><dt class="w-8 text-muted" dir="ltr">{{ $stars }}★</dt>
                    <dd class="h-2 flex-1 overflow-hidden rounded-full bg-line"><span class="block h-full rounded-full bg-accent" style="width: {{ $package->rating_count ? round(100 * $n / $package->rating_count) : 0 }}%"></span></dd>
                    <dd class="w-8 text-end text-faint">{{ $n }}</dd></div>
            @endforeach
        </dl>
    </div>

    @auth
        @if ($owned)
            <form method="post" action="{{ route('reviews.store', $package) }}" class="card mt-4 space-y-3 p-5">@csrf
                <h3 class="font-semibold">{{ $myReview ? __('Your review') : __('Review this package') }}</h3>
                @if ($myReview?->status === 'hidden')<p class="text-sm text-danger">{{ __('Your review is hidden by our moderators: :reason', ['reason' => $myReview->hidden_reason]) }}</p>@endif
                <fieldset class="flex flex-row-reverse justify-end gap-1 text-2xl" aria-label="{{ __('Rating') }}">
                    @foreach ([5, 4, 3, 2, 1] as $s)
                        <input class="peer sr-only" type="radio" id="star{{ $s }}" name="rating" value="{{ $s }}" @checked(old('rating', $myReview?->rating) == $s) required>
                        <label for="star{{ $s }}" class="cursor-pointer text-line hover:text-accent peer-checked:text-accent [&:hover~label]:text-accent" title="{{ $s }}">★</label>
                    @endforeach
                </fieldset>
                @error('rating')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                <input class="input" name="title" maxlength="120" value="{{ old('title', $myReview?->title) }}" placeholder="{{ __('Title (optional)') }}">
                <textarea class="input" name="body" rows="3" maxlength="2000" placeholder="{{ __('How was the translation in game? (optional)') }}">{{ old('body', $myReview?->body) }}</textarea>
                <div class="flex items-center gap-3"><button class="btn-light">{{ __('Publish review') }}</button></div>
            </form>
            @if ($myReview)
                <form method="post" action="{{ route('reviews.destroy', $package) }}" class="mt-2">@csrf @method('delete')<button class="text-sm text-faint hover:text-danger">{{ __('Delete my review') }}</button></form>
            @endif
        @else
            <p class="mt-3 text-sm text-muted">{{ __('Only players who own this package can review it.') }}</p>
        @endif
    @endauth

    <ul class="mt-4 space-y-3">
        @forelse ($reviews as $r)
            <li class="card p-4">
                <div class="flex flex-wrap items-center gap-3"><span class="text-accent" aria-label="{{ __(':n out of 5', ['n' => $r->rating]) }}">{{ str_repeat('★', $r->rating) }}</span>
                    @if ($r->title)<strong>{{ $r->title }}</strong>@endif</div>
                @if ($r->body)<p class="mt-2 whitespace-pre-line text-ink-2" dir="auto">{{ $r->body }}</p>@endif
                <p class="mt-2 text-xs text-faint">{{ $r->user->publicName() }} · {{ $r->created_at->translatedFormat('j M Y') }}@if ($r->package_version) · <span dir="ltr">v{{ $r->package_version }}</span>@endif · {{ __('Verified owner') }}</p>
            </li>
        @empty
            <li class="text-muted">{{ __('No reviews yet.') }}</li>
        @endforelse
    </ul>
    <div class="mt-3">{{ $reviews->fragment('reviews')->links() }}</div>
</section>
