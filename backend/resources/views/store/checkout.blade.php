@php($m = fn ($v) => \App\Support\Money::format($v))
<x-store.layout :title="__('Checkout')">
    <div class="mx-auto flex max-w-6xl flex-wrap items-start gap-8 px-4 py-10 sm:px-6">
        <form method="post" action="{{ route('checkout.store') }}" class="min-w-0 flex-[999_1_560px] space-y-6" id="checkout">
            @csrf
            <h1 class="h-display text-3xl">{{ __('Checkout') }}</h1>
            <section class="card space-y-4 p-6">
                <h2 class="text-lg font-semibold">{{ __('Billing details') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="billing_name">{{ __('Full name') }}</label><input class="input" id="billing_name" name="billing_name" required maxlength="120" value="{{ old('billing_name', $user->name) }}" autocomplete="name"></div>
                    <div><label class="label" for="billing_country">{{ __('Country') }}</label>
                        <select class="input" id="billing_country" name="billing_country" autocomplete="country">
                            @foreach (['SA' => __('Saudi Arabia'), 'AE' => __('United Arab Emirates'), 'KW' => __('Kuwait'), 'QA' => __('Qatar'), 'BH' => __('Bahrain'), 'OM' => __('Oman')] as $code => $name)
                                <option value="{{ $code }}" @selected(old('billing_country', $user->country_code ?? 'SA') === $code)>{{ $name }}</option>
                            @endforeach
                        </select></div>
                    <div class="sm:col-span-2"><label class="label" for="billing_vat_number">{{ __('VAT number (optional, for businesses)') }}</label><input class="input sm:w-1/2" dir="ltr" id="billing_vat_number" name="billing_vat_number" value="{{ old('billing_vat_number') }}" placeholder="3xxxxxxxxxxxxx3" inputmode="numeric"></div>
                </div>
            </section>
            <section class="card space-y-3 p-6">
                <h2 class="text-lg font-semibold">{{ __('Payment') }}</h2>
                @if ($methods)
                    <fieldset class="grid gap-3 sm:grid-cols-3"><legend class="sr-only">{{ __('Payment method') }}</legend>
                        @foreach ($methods as $key => $label)
                            <label class="flex h-14 cursor-pointer items-center justify-center rounded-xl border border-[#2A2E37] bg-ground font-semibold has-checked:border-accent has-checked:bg-accent/10">
                                <input type="radio" name="method" value="{{ $key }}" class="sr-only" @checked(old('method', array_key_first($methods)) === $key)> <span dir="ltr">{{ $label }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    <p class="text-muted">{{ __('The card form is provided by our payment provider. Your card details never reach LUGHA\'s servers.') }}</p>
                @else
                    <p class="text-muted">{{ __('You\'ll pay on the secure page of our payment provider: mada, Visa, Mastercard, Apple Pay or STC Pay. Your card details never reach LUGHA\'s servers.') }}</p>
                @endif
                <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="accept_terms" value="1" class="mt-1 size-4 accent-[#E9B44C]" required @checked(old('accept_terms'))>
                    <span>{{ __('I agree to the terms of sale and the') }} <a class="link" href="{{ route('pages.refunds') }}" target="_blank">{{ __('refund policy') }}</a>.</span></label>
            </section>
        </form>
        <aside class="card flex-[1_1_340px] space-y-4 p-6">
            <h2 class="text-lg font-semibold">{{ __('Order summary') }}</h2>
            @foreach ($quote->lines as $line)
                <div class="flex items-center gap-3">
                    <span class="h-16 w-12 shrink-0 rounded-md bg-[linear-gradient(160deg,#C08A3E,#1E1410)]" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1"><div class="font-semibold">{{ $line->package->name }}</div><div class="text-sm text-muted">{{ $line->package->game->title }}@if ($line->kind === 'upgrade') · {{ __('upgrade') }}@endif @if ($line->kind === 'gift') · {{ __('gift') }}@endif</div></div>
                    <div class="text-end"><div class="font-semibold">{{ $m($line->net()) }}</div>@if ($line->listAmount > $line->net())<s class="text-xs text-faint">{{ $m($line->listAmount) }}</s>@endif</div>
                </div>
            @endforeach
            <dl class="space-y-2 border-t border-line pt-4 text-[15px]">
                <div class="flex justify-between"><dt class="text-muted">{{ __('Subtotal') }}</dt><dd>{{ $m($quote->subtotal()) }}</dd></div>
                @if ($quote->discount())<div class="flex justify-between"><dt class="text-muted">{{ __('Discount') }} @if ($quote->coupon)({{ $quote->coupon->code }})@endif</dt><dd>−{{ $m($quote->discount()) }}</dd></div>@endif
                <div class="flex justify-between"><dt class="text-muted">{{ __('Includes VAT (15%)') }}</dt><dd>{{ $m($quote->vat()) }}</dd></div>
                <div class="flex justify-between pt-1 text-xl font-bold"><dt>{{ __('Total') }}</dt><dd>{{ $m($quote->total()) }}</dd></div>
            </dl>
            @if ($quote->isCheckoutable())
                <button form="checkout" class="btn-primary h-13 w-full text-lg">{{ $quote->total() > 0 ? __('Pay :amount', ['amount' => $m($quote->total())]) : __('Get it free') }}</button>
            @else
                <a href="{{ route('cart.show') }}" class="btn-ghost w-full">{{ __('Back to cart') }}</a>
            @endif
            <p class="text-xs text-faint">{{ __('Packages are added to your library as soon as the payment provider confirms payment.') }}</p>
        </aside>
    </div>
</x-store.layout>
