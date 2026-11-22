<x-store.layout :title="__('Arabic game localization')">
    <section class="bg-[radial-gradient(1200px_500px_at_85%_-10%,rgba(233,180,76,0.14),transparent_60%)]">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-12 px-4 pb-12 pt-16 sm:px-6 lg:pt-20">
            <div class="min-w-0 flex-[1_1_440px]">
                <p class="mb-4 inline-flex rounded-full border border-accent/25 bg-accent/10 px-3 py-1 text-sm text-accent">{{ __('The Arabic gaming localization ecosystem') }}</p>
                <h1 class="h-display text-4xl leading-[1.15] sm:text-6xl">{{ __('Play in your language.') }}<br><span class="text-ink-2">{{ __('Reviewed Arabic translations, shown while you play.') }}</span></h1>
                <p class="mt-5 max-w-xl text-lg text-ink-2">{{ __('Buy the localization package for your game, install it on your device, and the LUGHA app shows the translation over the screen in real time — no internet needed while you play.') }}</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('games.index') }}" class="btn-primary h-13 px-7">{{ __('Browse games') }}</a>
                    <a href="{{ route('pages.how') }}" class="btn-ghost h-13 px-6">{{ __('How LUGHA works') }}</a>
                </div>
            </div>
            <figure class="min-w-0 flex-[1_1_480px]">
                <div class="relative aspect-video overflow-hidden rounded-2xl border border-line bg-[linear-gradient(160deg,#2B3A4A_0%,#3B2E2A_55%,#1A1410_100%)]">
                    <div class="absolute inset-x-0 bottom-6 flex flex-col items-center gap-2 px-6 text-center">
                        <span class="text-sm text-[#A9B4C2]" dir="ltr" lang="en">“We leave at dawn. Nobody talks about tonight.”</span>
                        <span class="rounded-xl bg-[#0a0b0e]/80 px-5 py-2.5 text-lg font-semibold sm:text-2xl" dir="rtl" lang="ar"><span class="text-accent">ريم:</span> نطلع مع الفجر. وأحد ما يتكلم عن الليلة.</span>
                    </div>
                </div>
                <figcaption class="mt-3 text-sm text-faint">{{ __('The original text is read from the screen; the translation comes from the package installed on your device.') }}</figcaption>
            </figure>
        </div>
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-2.5 px-4 pb-12 text-sm text-faint sm:px-6">
            <span>{{ __('Apps:') }}</span>
            <span class="rounded-full border border-line px-3.5 py-1 text-ink-2">macOS — {{ __('coming soon') }}</span>
            <span class="rounded-full border border-line px-3.5 py-1 text-ink-2">Windows — {{ __('coming soon') }}</span>
            <span class="rounded-full border border-line px-3.5 py-1 text-ink-2">Android TV — {{ __('coming soon') }}</span>
            <span class="ms-2">{{ __('Supported games on:') }}</span>
            @foreach ($platforms as $p)<span class="chip" dir="ltr">{{ $p->name }}</span>@endforeach
        </div>
    </section>

    @if ($featured->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6" aria-labelledby="featured-h">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div><h2 id="featured-h" class="h-display text-3xl font-semibold">{{ __('Featured games') }}</h2><p class="mt-1 text-muted">{{ __('Ready-made, fully reviewed localization packages') }}</p></div>
            <a href="{{ route('games.index') }}" class="link">{{ __('All games') }} <span class="flip-rtl inline-block" aria-hidden="true">→</span></a>
        </div>
        <div class="grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-3 lg:grid-cols-4">
            @foreach ($featured as $game)<x-store.game-card :game="$game" />@endforeach
        </div>
    </section>
    @endif

    <section class="mx-auto flex max-w-7xl flex-wrap gap-8 px-4 py-12 sm:px-6">
        <div class="min-w-0 flex-[999_1_560px]">
            <h2 class="h-display mb-5 text-2xl font-semibold">{{ __('Popular packages') }}</h2>
            <div class="flex flex-col gap-3">
                @forelse ($popular as $p)
                    <a href="{{ route('packages.show', $p) }}" class="card flex items-center gap-4 p-3.5 hover:border-line-strong">
                        <span class="h-[72px] w-14 shrink-0 rounded-lg bg-[linear-gradient(160deg,#C08A3E,#1E1410)]" aria-hidden="true"></span>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="font-semibold">{{ $p->name }}</span>
                            <span class="truncate text-sm text-muted">{{ $p->game->title }} · {{ $p->categories->take(3)->pluck('name')->implode('، ') }}</span>
                        </span>
                        <x-store.price :package="$p" size="sm" />
                    </a>
                @empty
                    <p class="text-muted">{{ __('No packages yet.') }}</p>
                @endforelse
            </div>
        </div>
        <div class="min-w-0 flex-[1_1_340px]">
            <h2 class="h-display mb-5 text-2xl font-semibold">{{ __('Recently updated') }}</h2>
            @if ($updates->isEmpty())
                <p class="card p-5 text-muted">{{ __('Release notes appear here as packages are updated.') }}</p>
            @else
            <ol class="card divide-y divide-line">
                @foreach ($updates as $u)
                    <li class="flex flex-col gap-0.5 px-4 py-3.5">
                        <span class="flex justify-between gap-3"><a class="font-semibold hover:text-accent" href="{{ route('packages.show', $u->package) }}">{{ $u->package->game->title }} — {{ $u->package->name }}</a><span dir="ltr" class="font-mono text-sm text-accent">v{{ $u->version }}</span></span>
                        <span class="text-sm text-muted">{{ \Illuminate\Support\Str::limit($u->release_notes, 70) }} · {{ $u->released_at->translatedFormat('j F') }}</span>
                    </li>
                @endforeach
            </ol>
            @endif
        </div>
    </section>

    @if ($recent->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6">
        <h2 class="h-display mb-6 text-2xl font-semibold">{{ __('Recently added') }}</h2>
        <div class="grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-4">
            @foreach ($recent as $game)<x-store.game-card :game="$game" />@endforeach
        </div>
    </section>
    @endif

    @include('store.partials.how-steps')
</x-store.layout>
