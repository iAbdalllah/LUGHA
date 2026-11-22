<x-store.layout :title="$package->name.' — '.$package->game->title">
    <div class="mx-auto flex max-w-7xl flex-wrap gap-10 px-4 py-10 sm:px-6">
        <div class="min-w-0 flex-[999_1_560px] space-y-8">
            <div>
                <nav aria-label="{{ __('Breadcrumb') }}" class="text-sm text-faint"><a class="hover:text-ink" href="{{ route('games.index') }}">{{ __('Store') }}</a> / <a class="hover:text-ink" href="{{ route('games.show', $package->game) }}">{{ $package->game->title }}</a></nav>
                <h1 class="h-display mt-2 text-4xl">{{ $package->name }}</h1>
                @if ($package->summary)<p class="mt-2 text-lg text-ink-2">{{ $package->summary }}</p>@endif
                <div class="mt-3 flex flex-wrap gap-2"><span class="chip">{{ $package->style->name }}</span><span class="chip" dir="ltr">{{ $package->locale }}</span>@foreach ($package->platforms as $pl)<span class="chip" dir="ltr">{{ $pl->name }}</span>@endforeach
                    @if ($package->rating_count)<a href="#reviews" class="chip text-accent">★ {{ number_format($package->rating_avg, 1) }} ({{ $package->rating_count }})</a>@endif
                    @if ($package->qa_score !== null)<a href="#quality" class="chip border-accent/50 text-accent">{{ __('QA score :n/100', ['n' => $package->qa_score]) }}</a>@endif</div>
            </div>
            <section>
                <h2 class="mb-3 text-xl font-semibold">{{ __('What\'s included') }}</h2>
                @if ($package->is_bundle && $package->inclusions->isNotEmpty())
                    <p class="mb-3 text-muted">{{ __('This bundle includes:') }} {{ $package->inclusions->pluck('name')->implode('، ') }}</p>
                @endif
                <ul class="grid gap-2 sm:grid-cols-2">
                    @foreach ($categories as $c)<li class="card flex items-center gap-3 px-4 py-3"><span class="text-accent" aria-hidden="true">✓</span>{{ $c->name }}</li>@endforeach
                </ul>
            </section>
            <section>
                <h2 class="mb-3 text-xl font-semibold">{{ __('Versions & release notes') }}</h2>
                @forelse ($package->versions as $v)
                    <article class="border-b border-line py-4">
                        <div class="flex flex-wrap items-center gap-3"><span dir="ltr" class="font-mono text-accent">v{{ $v->version }}</span><span class="text-sm text-faint">{{ $v->released_at?->translatedFormat('j F Y') }}</span>
                            @if ($v->minGameVersion)<span class="chip" dir="ltr">{{ __('Game') }} {{ $v->minGameVersion->version }}{{ $v->maxGameVersion ? ' – '.$v->maxGameVersion->version : '+' }}</span>@endif</div>
                        @if ($v->release_notes)<p class="mt-2 whitespace-pre-line text-ink-2">{{ $v->release_notes }}</p>@endif
                    </article>
                @empty
                    <p class="text-muted">{{ __('The first release of this package is in preparation.') }}</p>
                @endforelse
            </section>
            @if ($package->qa_score !== null)
                <section id="quality" class="scroll-mt-24">
                    <h2 class="mb-3 text-xl font-semibold">{{ __('Quality') }}</h2>
                    <div class="card p-5">
                        <div class="flex items-baseline gap-3"><span class="text-4xl font-bold text-accent">{{ $package->qa_score }}</span><span class="text-muted">/ 100</span></div>
                        @php($parts = $package->qa_breakdown['parts'] ?? [])
                        <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                            <div><dt class="text-sm text-muted">{{ __('Coverage') }}</dt><dd class="text-lg font-semibold">{{ round($parts['coverage'] ?? 0) }}%</dd></div>
                            <div><dt class="text-sm text-muted">{{ __('Checked by a second editor') }}</dt><dd class="text-lg font-semibold">{{ round($parts['review'] ?? 0) }}%</dd></div>
                            <div><dt class="text-sm text-muted">{{ __('Player-reported problems') }}</dt><dd class="text-lg font-semibold">{{ __(':n confirmed (90 days)', ['n' => $package->qa_breakdown['confirmed_reports_90d'] ?? 0]) }}</dd></div>
                        </dl>
                        <p class="mt-3 text-xs text-faint">{{ __('The score combines coverage (40%), lines approved by a second editor (30%) and confirmed player reports (30%). Ratings are shown separately and do not affect it.') }}</p>
                    </div>
                </section>
            @endif
            @include('store.packages._reviews')
        </div>
        <aside class="card h-fit flex-[1_1_320px] space-y-4 p-6">
            <x-store.price :package="$package" :upgrade-amount="$owned ? null : $upgrade?->amount" />
            <p class="text-sm text-muted">{{ match ($package->update_policy->value) {
                'version_line' => __('All :major.x updates included', ['major' => $package->current_major]),
                'duration' => __(':n months of updates included', ['n' => $package->update_months]),
                default => __('All future updates included, per the package terms'),
            } }}</p>
            @include('store.partials.buy-buttons', ['package' => $package, 'owned' => $owned, 'inCart' => auth()->check() && auth()->user()->cartItems()->where('package_id', $package->id)->where('as_gift', false)->exists(), 'recommended' => true])
            <p class="text-xs text-faint">{{ __('Prices include 15% VAT.') }} <a class="link" href="{{ route('pages.refunds') }}">{{ __('Refund policy') }}</a></p>
        </aside>
    </div>
</x-store.layout>
