@props(['game'])
@php
    $prices = $game->publishedPackages->map(fn ($p) => $p->priceFor()?->effectiveAmount())->filter();
    $palette = ['#C08A3E', '#3E6A8A', '#6B4E8A', '#3F7A64', '#8A4E4E', '#5A6B3E'];
    $tone = $palette[crc32($game->slug) % count($palette)];
@endphp
<a href="{{ route('games.show', $game) }}" class="card group flex flex-col overflow-hidden transition-colors hover:border-line-strong">
    <div class="relative flex aspect-[3/4] items-end p-4" style="background: {{ $game->cover_path ? 'center/cover url('.e($game->coverUrl()).')' : "linear-gradient(160deg, {$tone}, #1a1410 85%)" }}">
        @if ($game->status->value === 'coming_soon')
            <span class="absolute top-3 start-3 rounded-md bg-ground/75 px-2.5 py-0.5 text-xs">{{ __('Coming soon') }}</span>
        @elseif ($game->published_at?->gt(now()->subDays(21)))
            <span class="absolute top-3 start-3 rounded-md bg-ground/75 px-2.5 py-0.5 text-xs">{{ __('New') }}</span>
        @endif
        @unless ($game->cover_path)
            <span class="h-display text-2xl leading-tight [text-shadow:0_2px_12px_rgba(0,0,0,.5)]">{{ $game->title }}</span>
        @endunless
    </div>
    <div class="flex flex-1 flex-col gap-2 p-4">
        @if ($game->cover_path)<span class="font-semibold">{{ $game->title }}</span>@endif
        <span class="text-sm text-muted" dir="ltr">{{ $game->platforms->pluck('name')->implode(' · ') }}</span>
        <div class="mt-auto flex items-center justify-between text-sm">
            <span class="text-muted">{{ trans_choice(':count package|:count packages', $game->publishedPackages->count(), ['count' => $game->publishedPackages->count()]) }}</span>
            @if ($prices->isNotEmpty())<span class="font-semibold">{{ __('From :price', ['price' => \App\Support\Money::format($prices->min())]) }}</span>@endif
        </div>
    </div>
</a>
