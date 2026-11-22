<x-store.layout :title="__('My library')">
    <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-10 sm:px-6 lg:flex-row">
        <x-store.account-nav />
        <div class="min-w-0 flex-1 space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div><h1 class="h-display text-3xl">{{ __('My library') }}</h1><p class="mt-1 text-muted">{{ trans_choice(':count package|:count packages', $entitlements->count(), ['count' => $entitlements->count()]) }}</p></div>
                <a href="{{ route('gift.show') }}" class="btn-ghost">{{ __('Redeem a gift code') }}</a>
            </div>
            @forelse ($entitlements as $e)
                @php($latest = $e->package->versions->first(fn ($v) => $e->covers($v)))
                <article class="card flex flex-wrap items-center gap-4 p-4">
                    <span class="h-20 w-15 shrink-0 rounded-lg bg-[linear-gradient(160deg,#C08A3E,#1E1410)]" aria-hidden="true"></span>
                    <div class="min-w-0 flex-[1_1_260px]">
                        <h2 class="text-lg font-semibold"><a class="hover:text-accent" href="{{ route('games.show', $e->package->game) }}">{{ $e->package->game->title }} — {{ $e->package->name }}</a></h2>
                        <p class="text-sm text-muted">
                            @if ($latest){{ __('Latest version for you: :v', ['v' => $latest->version]) }}@else{{ __('First release in preparation') }}@endif
                            · @switch($e->source->value) @case('gift'){{ __('Gift') }}@break @case('bundle'){{ __('Part of a bundle') }}@break @case('upgrade'){{ __('Upgrade') }}@break @default{{ __('Purchased :date', ['date' => $e->granted_at->translatedFormat('j M Y')]) }} @endswitch
                        </p>
                        <p class="mt-1 text-xs text-faint">{{ $e->update_major ? __('Updates for version :major.x', ['major' => $e->update_major]) : ($e->updates_until ? __('Updates until :date', ['date' => $e->updates_until->translatedFormat('j M Y')]) : __('All future updates')) }}</p>
                    </div>
                    <a href="{{ route('downloads') }}" class="btn-ghost">{{ __('How to install') }}</a>
                </article>
            @empty
                <div class="card p-10 text-center"><p class="text-muted">{{ __('Packages you buy appear here.') }}</p><a href="{{ route('games.index') }}" class="btn-primary mt-4">{{ __('Browse games') }}</a></div>
            @endforelse
        </div>
    </div>
</x-store.layout>
