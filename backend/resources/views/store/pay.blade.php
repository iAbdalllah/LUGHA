<x-store.layout :title="__('Payment')">
    <div class="mx-auto max-w-xl px-4 py-12">
        <h1 class="h-display text-3xl">{{ __('Pay :amount', ['amount' => \App\Support\Money::format($payment->amount, $payment->currency)]) }}</h1>
        <p class="mt-2 text-muted">{{ __('The card form below is provided by our payment provider. Your card details never reach LUGHA\'s servers.') }}</p>
        <div class="card mt-6 p-6" dir="ltr">
            <script>var wpwlOptions = { locale: @json(app()->getLocale()), style: 'card', brandDetection: true };</script>
            <script src="{{ $scriptUrl }}"></script>
            <form action="{{ $returnUrl }}" class="paymentWidgets" data-brands="{{ $brands }}"></form>
        </div>
        <a href="{{ route('orders.show', $payment->order) }}" class="link mt-4 inline-block text-sm">{{ __('Cancel and return to the order') }}</a>
    </div>
</x-store.layout>
