@props(['package', 'upgradeAmount' => null, 'size' => 'lg'])
@php($price = $package->priceFor())
@if ($price)
    <div class="flex flex-wrap items-baseline gap-x-2">
        @if ($upgradeAmount !== null)
            <span @class(['h-display', 'text-4xl' => $size === 'lg', 'text-xl' => $size !== 'lg'])>{{ \App\Support\Money::format($upgradeAmount) }}</span>
            <s class="text-sm text-faint">{{ \App\Support\Money::format($price->effectiveAmount()) }}</s>
            <span class="w-full text-xs text-accent">{{ __('Upgrade price — you own a lower tier') }}</span>
        @else
            <span @class(['h-display', 'text-4xl' => $size === 'lg', 'text-xl' => $size !== 'lg'])>{{ \App\Support\Money::format($price->effectiveAmount()) }}</span>
            @if ($price->saleActive())<s class="text-sm text-faint">{{ \App\Support\Money::format($price->base_amount) }}</s>@endif
        @endif
    </div>
@endif
