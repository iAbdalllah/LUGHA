{{-- $package, $owned (bool), $inCart (bool), $recommended (bool) --}}
@if (! $package->isPurchasable())
    <p class="rounded-xl border border-line px-4 py-3 text-center text-sm text-muted">{{ __('Not on sale yet') }}</p>
@elseif ($owned)
    <a href="{{ route('library') }}" class="btn-ghost w-full">{{ __('In your library') }} ✓</a>
@elseif ($inCart)
    <a href="{{ route('cart.show') }}" class="btn-ghost w-full border-accent/50 text-accent">{{ __('In cart — view cart') }}</a>
@else
    <form method="post" action="{{ route('cart.add') }}" class="flex flex-col gap-2">
        @csrf
        <input type="hidden" name="package" value="{{ $package->slug }}">
        <button @class(['w-full', 'btn-primary' => $recommended, 'btn-light' => ! $recommended])>{{ __('Add to cart') }}</button>
        <button name="buy_now" value="1" class="text-sm text-muted hover:text-ink">{{ __('Buy now') }}</button>
    </form>
@endif
@if ($package->isPurchasable())
    <form method="post" action="{{ route('cart.add') }}" class="text-center">
        @csrf
        <input type="hidden" name="package" value="{{ $package->slug }}"><input type="hidden" name="as_gift" value="1">
        <button class="text-xs text-faint underline-offset-4 hover:text-ink hover:underline">{{ __('Buy as a gift') }}</button>
    </form>
@endif
