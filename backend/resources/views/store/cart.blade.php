@php($m = fn ($v) => \App\Support\Money::format($v))
<x-store.layout :title="__('Cart')">
    <x-store.page-header :title="__('Cart')" />
    <div class="mx-auto flex max-w-7xl flex-wrap items-start gap-8 px-4 py-8 sm:px-6">
        <div class="min-w-0 flex-[999_1_560px] space-y-3">
            @forelse ($quote->lines as $i => $line)
                @php($item = $items->first(fn ($ci) => $ci->package_id === $line->package->id && $ci->as_gift === ($line->kind === 'gift')))
                <div class="card flex flex-wrap items-center gap-4 p-4">
                    <span class="h-20 w-15 shrink-0 rounded-lg bg-[linear-gradient(160deg,#C08A3E,#1E1410)]" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('packages.show', $line->package) }}" class="font-semibold hover:text-accent">{{ $line->package->name }}</a>
                        <p class="text-sm text-muted">{{ $line->package->game->title }}
                            @if ($line->kind === 'upgrade') · <span class="text-accent">{{ __('Upgrade price applied') }}</span>@endif
                            @if ($line->kind === 'gift') · <span class="text-accent">{{ __('Gift — you\'ll get a code to share') }}</span>@endif
                        </p>
                        @if ($line->error)<p class="mt-1 text-sm text-danger">{{ $line->error }}</p>@endif
                    </div>
                    <div class="text-end">
                        <div class="font-semibold">{{ $m($line->net()) }}</div>
                        @if ($line->listAmount > $line->net())<s class="text-sm text-faint">{{ $m($line->listAmount) }}</s>@endif
                    </div>
                    @if ($item)
                        <form method="post" action="{{ route('cart.remove', $item) }}">@csrf @method('delete')<button class="btn-ghost h-9 px-3 text-sm" aria-label="{{ __('Remove :name', ['name' => $line->package->name]) }}">{{ __('Remove') }}</button></form>
                    @endif
                </div>
            @empty
                <div class="card p-10 text-center"><p class="text-muted">{{ __('Your cart is empty.') }}</p><a href="{{ route('games.index') }}" class="btn-primary mt-4">{{ __('Browse games') }}</a></div>
            @endforelse
        </div>
        @if ($quote->lines)
        <aside class="card flex-[1_1_320px] space-y-4 p-6">
            <h2 class="text-lg font-semibold">{{ __('Summary') }}</h2>
            @if ($quote->coupon)
                <form method="post" action="{{ route('cart.coupon.remove') }}" class="flex items-center justify-between rounded-xl bg-success/10 px-3 py-2 text-sm text-success">@csrf @method('delete')
                    <span>{{ __('Coupon :code applied', ['code' => $quote->coupon->code]) }}</span><button class="underline">{{ __('Remove') }}</button></form>
            @else
                <form method="post" action="{{ route('cart.coupon') }}" class="flex gap-2">@csrf
                    <label class="flex-1"><span class="sr-only">{{ __('Coupon code') }}</span><input name="code" class="input uppercase" dir="ltr" placeholder="{{ __('Coupon code') }}" value="{{ old('code') }}"></label>
                    <button class="btn-ghost">{{ __('Apply') }}</button>
                </form>
                @error('code')<p class="text-sm text-danger">{{ $message }}</p>@enderror
            @endif
            <dl class="space-y-2 border-t border-line pt-4 text-[15px]">
                <div class="flex justify-between"><dt class="text-muted">{{ __('Subtotal') }}</dt><dd>{{ $m($quote->subtotal()) }}</dd></div>
                @if ($quote->discount())<div class="flex justify-between"><dt class="text-muted">{{ __('Discount') }}</dt><dd>−{{ $m($quote->discount()) }}</dd></div>@endif
                <div class="flex justify-between"><dt class="text-muted">{{ __('Includes VAT (15%)') }}</dt><dd>{{ $m($quote->vat()) }}</dd></div>
                <div class="flex justify-between pt-1 text-xl font-bold"><dt>{{ __('Total') }}</dt><dd>{{ $m($quote->total()) }}</dd></div>
            </dl>
            @if ($quote->isCheckoutable())
                <a href="{{ route('checkout.show') }}" class="btn-primary h-13 w-full">{{ __('Checkout') }}</a>
            @else
                <p class="text-sm text-danger">{{ __('Remove the items marked above to continue.') }}</p>
            @endif
        </aside>
        @endif
    </div>
</x-store.layout>
