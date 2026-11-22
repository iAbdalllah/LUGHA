@php
    $recommendedId = $packages->count() > 2 ? $packages->sortBy('tier_rank')->values()[1]->id : null;
    $updateLabel = fn ($p) => match ($p->update_policy->value) {
        'version_line' => __('All :major.x updates included', ['major' => $p->current_major]),
        'duration' => __(':n months of updates included', ['n' => $p->update_months]),
        default => __('All future updates included, per the package terms'),
    };
@endphp
<x-store.layout :title="$game->title" :description="\Illuminate\Support\Str::limit($game->description, 150)">
    <section class="bg-[linear-gradient(180deg,rgba(192,138,62,0.20),rgba(14,15,18,0)_70%)]">
        <div class="mx-auto flex max-w-7xl flex-wrap gap-10 px-4 pb-8 pt-10 sm:px-6">
            <div class="aspect-[3/4] w-56 shrink-0 rounded-2xl border border-[#3A3328] sm:w-64" style="background: {{ $game->cover_path ? 'center/cover url('.e($game->coverUrl()).')' : 'linear-gradient(160deg,#C08A3E,#5A3A1E 60%,#1E1410)' }}" role="img" aria-label="{{ __('Cover of :title', ['title' => $game->title]) }}"></div>
            <div class="flex min-w-0 flex-[999_1_480px] flex-col gap-4">
                <nav aria-label="{{ __('Breadcrumb') }}" class="text-sm text-faint"><a class="hover:text-ink" href="{{ route('games.index') }}">{{ __('Store') }}</a> / <span class="text-ink">{{ $game->title }}</span></nav>
                <div class="flex flex-wrap gap-2">
                    @if ($lastUpdate)<span class="chip-accent">{{ __('Updated :date', ['date' => \Illuminate\Support\Carbon::parse($lastUpdate)->translatedFormat('j F Y')]) }}</span>@endif
                    @foreach ($packages->pluck('style')->unique('id') as $style)<span class="chip">{{ $style->name }}</span>@endforeach
                    @if ($game->status->value === 'coming_soon')<span class="chip">{{ __('Coming soon') }}</span>@endif
                </div>
                <h1 class="h-display text-4xl sm:text-5xl">{{ $game->title }}
                    @if (app()->getLocale() === 'ar' && $game->getTranslation('title', 'en', false))<span class="ms-2 text-xl font-medium text-faint" dir="ltr" lang="en">{{ $game->getTranslation('title', 'en') }}</span>@endif
                </h1>
                @if ($game->description)<p class="max-w-3xl text-lg text-ink-2">{{ $game->description }}</p>@endif
                <dl class="mt-2 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @foreach ([
                        [__('Developer'), $game->developer], [__('Publisher'), $game->publisher],
                        [__('Platforms'), $game->platforms->pluck('name')->implode(' · ')],
                        [__('Supported game versions'), $game->versions->isEmpty() ? null : $game->versions->first()->version.' – '.$game->versions->last()->version],
                    ] as [$k, $v])
                        @if ($v)<div class="card px-4 py-3"><dt class="text-sm text-faint">{{ $k }}</dt><dd class="mt-0.5 font-semibold" dir="auto">{{ $v }}</dd></div>@endif
                    @endforeach
                </dl>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6" aria-labelledby="packages-h">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div><h2 id="packages-h" class="h-display text-3xl font-semibold">{{ __('Localization packages') }}</h2><p class="mt-1 text-muted">{{ __('Each package is bought on its own, and you can upgrade later for the difference.') }}</p></div>
            <span class="text-sm text-faint">{{ __('Prices in SAR, including 15% VAT') }}</span>
        </div>
        @if ($packages->isEmpty())
            <p class="card p-8 text-center text-muted">{{ __('Packages for this game are being prepared.') }}</p>
        @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-{{ min(4, $packages->count()) }}">
            @foreach ($packages as $p)
                @php($rec = $p->id === $recommendedId)
                <article @class(['relative flex flex-col gap-4 rounded-2xl p-6', 'border border-accent/55 bg-[linear-gradient(180deg,#1E1B14,#16181D)]' => $rec, 'card' => ! $rec])>
                    @if ($rec)<span class="absolute -top-3 start-5 rounded-full bg-accent px-3 py-0.5 text-xs font-bold text-accent-ink">{{ __('Most chosen') }}</span>@endif
                    <div><h3 class="h-display text-xl font-semibold"><a href="{{ route('packages.show', $p) }}" class="hover:text-accent">{{ $p->name }}</a></h3>@if ($p->summary)<p class="mt-1 text-sm text-muted">{{ $p->summary }}</p>@endif</div>
                    <x-store.price :package="$p" :upgrade-amount="in_array($p->id, $owned) ? null : $upgrades->get($p->id)" />
                    <ul class="flex flex-col gap-2 text-[15px]">
                        @if ($p->is_bundle)
                            @foreach ($p->inclusions as $inc)<li class="flex gap-2.5"><span class="text-accent" aria-hidden="true">✓</span>{{ __('Everything in :name', ['name' => $inc->name]) }}</li>@endforeach
                            @foreach ($p->categories as $c)<li class="flex gap-2.5"><span class="text-accent" aria-hidden="true">✓</span>{{ $c->name }}</li>@endforeach
                        @else
                            @foreach ($p->categories->take(6) as $c)<li class="flex gap-2.5"><span class="text-accent" aria-hidden="true">✓</span>{{ $c->name }}</li>@endforeach
                            @if ($p->categories->count() > 6)<li class="text-sm text-muted">{{ __('+ :n more', ['n' => $p->categories->count() - 6]) }}</li>@endif
                        @endif
                    </ul>
                    <p class="mt-auto text-xs text-faint">{{ $updateLabel($p) }}</p>
                    @include('store.partials.buy-buttons', ['package' => $p, 'owned' => in_array($p->id, $owned), 'inCart' => in_array($p->id, $inCart), 'recommended' => $rec])
                </article>
            @endforeach
        </div>

        <h2 class="h-display mb-4 mt-12 text-2xl font-semibold">{{ __('Compare content') }}</h2>
        <div class="relative overflow-x-auto rounded-2xl border border-line">
            <table class="w-full min-w-[640px] border-collapse text-[15px]">
                <caption class="sr-only">{{ __('Which content each package includes') }}</caption>
                <thead class="bg-surface"><tr>
                    <th scope="col" class="px-4 py-3.5 text-start font-semibold">{{ __('Content category') }}</th>
                    @foreach ($packages as $p)<th scope="col" class="px-4 py-3.5 font-semibold">{{ $p->name }}</th>@endforeach
                </tr></thead>
                <tbody>
                    @foreach ($categories as $c)
                        <tr class="border-t border-line">
                            <th scope="row" class="px-4 py-3 text-start font-medium">{{ $c->name }}</th>
                            @foreach ($packages as $p)
                                @php($has = in_array($c->id, $columns[$p->id]))
                                <td @class(['px-4 py-3 text-center', 'text-accent' => $has, 'text-[#5A606B]' => ! $has])>
                                    <span aria-hidden="true">{{ $has ? '✓' : '—' }}</span><span class="sr-only">{{ $has ? __('Included') : __('Not included') }}</span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>

    @if ($game->media->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        <h2 class="h-display mb-4 text-2xl font-semibold">{{ __('Translated subtitles in game') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($game->media as $m)
                <figure><img src="{{ $m->url() }}" alt="{{ $m->caption ?: __('Screenshot of :title with Arabic subtitles', ['title' => $game->title]) }}" class="aspect-video w-full rounded-xl border border-line object-cover" loading="lazy">
                    @if ($m->caption)<figcaption class="mt-2 text-sm text-faint">{{ $m->caption }}</figcaption>@endif</figure>
            @endforeach
        </div>
    </section>
    @endif
</x-store.layout>
