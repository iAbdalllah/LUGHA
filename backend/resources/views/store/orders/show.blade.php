@php
    $m = fn ($v) => \App\Support\Money::format($v, $order->currency);
    $zatca = $order->invoice_number && \App\Domain\Invoicing\ZatcaQr::enabled();
    $qr = $zatca ? \App\Domain\Invoicing\ZatcaQr::svg(\App\Domain\Invoicing\ZatcaQr::payload($seller['name'], $seller['vat_number'], $order->paid_at, $order->total_amount, $order->vat_amount)) : null;
@endphp
<x-store.layout :title="__('Order :n', ['n' => $order->number ?? ''])">
    <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-10 sm:px-6 lg:flex-row">
        <div class="print:hidden"><x-store.account-nav /></div>
        <div class="min-w-0 flex-1 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="h-display text-3xl">{{ $order->invoice_number ? ($order->isB2B() ? __('Tax invoice') : __('Simplified tax invoice')) : __('Order') }}</h1>
                    <p class="mt-1 font-mono text-muted" dir="ltr">{{ $order->invoice_number ?? $order->public_id }}</p>
                </div>
                <span @class(['rounded-full px-4 py-1.5 text-sm font-semibold',
                    'bg-success/15 text-success' => $order->status->value === 'paid',
                    'bg-accent/15 text-accent' => in_array($order->status->value, ['pending', 'awaiting_payment']),
                    'bg-danger/15 text-danger' => in_array($order->status->value, ['failed', 'cancelled']),
                    'bg-surface-2 text-ink-2' => in_array($order->status->value, ['refunded', 'partially_refunded'])])>{{ $order->status->label() }}</span>
            </div>

            @if ($order->invoice_number)
                <button type="button" onclick="window.print()" class="btn-ghost h-9 px-3 text-sm print:hidden">{{ __('Print / save as PDF') }}</button>
            @endif
            <div class="print:hidden space-y-6">
            @if ($order->status->value === 'awaiting_payment')
                <div class="card flex flex-wrap items-center justify-between gap-4 border-accent/40 p-5">
                    <p class="text-ink-2">{{ __('We\'re waiting for the payment provider to confirm this payment. This page updates when it does.') }}</p>
                    <form method="post" action="{{ route('orders.pay', $order) }}">@csrf<button class="btn-ghost">{{ __('Try paying again') }}</button></form>
                </div>
            @elseif ($order->status->isPayable())
                <div class="card flex flex-wrap items-center justify-between gap-4 p-5">
                    <p class="text-ink-2">{{ __('This order has not been paid.') }}</p>
                    <form method="post" action="{{ route('orders.pay', $order) }}">@csrf<button class="btn-primary">{{ __('Pay :amount', ['amount' => $m($order->total_amount)]) }}</button></form>
                </div>
            @elseif ($order->status->value === 'paid')
                <div class="card flex flex-wrap items-center justify-between gap-4 border-success/30 p-5">
                    <p class="text-success">{{ __('Payment confirmed. Your packages are in your library.') }}</p>
                    <a href="{{ route('library') }}" class="btn-primary">{{ __('Go to library') }}</a>
                </div>
            @endif

            </div>
            <section class="card p-6 print:border-0 print:p-0">
                <div class="mb-6 grid gap-4 text-sm sm:grid-cols-3">
                    <div><p class="text-faint">{{ __('Seller') }}</p><p class="font-semibold">{{ $seller['name'] }}</p>
                        @if ($seller['vat_number'])<p class="text-muted">{{ __('VAT number') }} <span dir="ltr">{{ $seller['vat_number'] }}</span></p>@endif
                        @if ($seller['cr_number'] ?? null)<p class="text-muted">{{ __('CR number') }} <span dir="ltr">{{ $seller['cr_number'] }}</span></p>@endif
                        @if ($seller['address'] ?? null)<p class="text-muted">{{ $seller['address'] }}</p>@endif</div>
                    <div><p class="text-faint">{{ __('Billed to') }}</p><p class="font-semibold">{{ $order->billing['name'] ?? $order->user->name }}</p>@if (! empty($order->billing['vat_number']))<p dir="ltr" class="text-muted">{{ __('VAT') }} {{ $order->billing['vat_number'] }}</p>@endif</div>
                    <div><p class="text-faint">{{ __('Date') }}</p><p class="font-semibold">{{ ($order->paid_at ?? $order->created_at)->translatedFormat('j F Y, H:i') }}</p></div>
                </div>
                <div class="relative overflow-x-auto">
                <table class="w-full min-w-[520px] text-[15px]">
                    <thead><tr class="border-b border-line text-muted">
                        <th scope="col" class="py-2 text-start font-medium">{{ __('Item') }}</th>
                        <th scope="col" class="py-2 text-start font-medium">{{ __('VAT') }}</th>
                        <th scope="col" class="py-2 text-end font-medium">{{ __('Amount') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($order->items as $item)
                        <tr class="border-b border-line align-top">
                            <td class="py-3"><span class="font-semibold">{{ $item->package->name }}</span><span class="block text-sm text-muted">{{ $item->package->game->title }}@if ($item->kind !== 'purchase') · {{ __($item->kind) }}@endif</span>
                                @if ($item->kind === 'gift' && $item->giftCode)
                                    <span class="mt-2 block rounded-lg bg-accent/10 px-3 py-2 text-sm">{{ __('Gift code to share:') }} <code dir="ltr" class="font-mono text-accent select-all">{{ $item->giftCode->code_encrypted }}</code>
                                        @if ($item->giftCode->redeemed_at) · {{ __('redeemed') }}@endif</span>
                                @endif
                                @if ($item->refunded_amount)<span class="block text-sm text-danger">{{ __('Refunded :amount', ['amount' => $m($item->refunded_amount)]) }}</span>@endif
                            </td>
                            <td class="py-3 text-muted">{{ $m($item->vat_amount) }}</td>
                            <td class="py-3 text-end">{{ $m($item->unit_amount) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                <dl class="ms-auto mt-4 max-w-xs space-y-1.5 text-[15px]">
                    @if ($order->discount_amount)<div class="flex justify-between"><dt class="text-muted">{{ __('Discount') }}</dt><dd>−{{ $m($order->discount_amount) }}</dd></div>@endif
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Total excluding VAT') }}</dt><dd>{{ $m($order->total_amount - $order->vat_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('VAT (15%)') }}</dt><dd>{{ $m($order->vat_amount) }}</dd></div>
                    <div class="flex justify-between text-lg font-bold"><dt>{{ __('Total') }}</dt><dd>{{ $m($order->total_amount) }}</dd></div>
                </dl>
                @if ($qr)
                    <div class="mt-6 flex items-center gap-4 border-t border-line pt-5">
                        <div class="rounded-lg bg-white p-2" role="img" aria-label="{{ __('ZATCA QR code') }}">{!! $qr !!}</div>
                        <p class="text-xs text-faint">{{ __('Scan with the ZATCA app to verify this invoice.') }}</p>
                    </div>
                @elseif ($order->invoice_number && ! app()->environment('production'))
                    <p class="mt-6 text-xs text-danger print:hidden" dir="ltr">Dev note: set LUGHA_SELLER_VAT_NUMBER to show the ZATCA QR code.</p>
                @endif
            </section>

            @foreach ($order->refunds as $refund)
                <section class="card p-6 print:break-before-page">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div><h2 class="text-lg font-semibold">{{ __('Credit note') }}</h2>
                            <p class="font-mono text-sm text-muted" dir="ltr">{{ $refund->credit_note_number }}</p>
                            <p class="mt-1 text-sm text-faint">{{ __('For invoice :n · :date', ['n' => $order->invoice_number, 'date' => $refund->processed_at->translatedFormat('j F Y, H:i')]) }}</p></div>
                        @if (\App\Domain\Invoicing\ZatcaQr::enabled())
                            <div class="rounded-lg bg-white p-1.5" role="img" aria-label="{{ __('ZATCA QR code') }}">{!! \App\Domain\Invoicing\ZatcaQr::svg(\App\Domain\Invoicing\ZatcaQr::payload($seller['name'], $seller['vat_number'], $refund->processed_at, $refund->amount, $refund->vat_amount), 112) !!}</div>
                        @endif
                    </div>
                    <dl class="mt-4 space-y-1.5 text-[15px]">
                        <div class="flex justify-between"><dt class="text-muted">{{ $refund->orderItem?->package->name }}</dt><dd>−{{ $m($refund->amount) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted">{{ __('VAT (15%)') }}</dt><dd>−{{ $m($refund->vat_amount) }}</dd></div>
                    </dl>
                </section>
            @endforeach
            @if ($order->isPaidOrRefunded() && ($refundable = $order->items->filter(fn ($i) => $i->refundableAmount() > 0))->isNotEmpty())
                <details class="card p-5 print:hidden">
                    <summary class="cursor-pointer font-semibold">{{ __('Request a refund') }}</summary>
                    <form method="post" action="{{ route('orders.refund-request', $order) }}" class="mt-4 space-y-3">@csrf
                        <div><label class="label" for="refund-item">{{ __('Which item?') }}</label>
                            <select class="input" id="refund-item" name="item">@foreach ($refundable as $i)<option value="{{ $i->id }}">{{ $i->package->name }} — {{ $m($i->refundableAmount()) }}</option>@endforeach</select></div>
                        <div><label class="label" for="refund-reason">{{ __('Tell us why (optional)') }}</label><textarea class="input h-24 py-2.5" id="refund-reason" name="reason" maxlength="2000"></textarea></div>
                        <p class="text-xs text-faint"><a class="link" href="{{ route('pages.refunds') }}">{{ __('Refund policy') }}</a></p>
                        <button class="btn-ghost">{{ __('Send request') }}</button>
                    </form>
                </details>
            @endif
            <p class="text-sm text-faint print:hidden">{{ __('Need help with this order?') }} <a class="link" href="{{ route('support.index') }}">{{ __('Contact support') }}</a></p>
        </div>
    </div>
</x-store.layout>
