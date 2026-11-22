<x-store.layout title="Fake payment">
    <div class="mx-auto max-w-md px-4 py-14">
        <p class="mb-4 rounded-xl border border-danger/40 bg-danger/10 p-3 text-sm text-danger" dir="ltr">Development only — this stands in for the payment provider's hosted page and does not exist in production.</p>
        <div class="card space-y-4 p-6" dir="ltr">
            <h1 class="text-xl font-semibold">Pay {{ \App\Support\Money::format($payment->amount, $payment->currency, 'en') }}</h1>
            <p class="font-mono text-sm text-muted">{{ $payment->gateway_ref }}</p>
            <form method="post" action="{{ route('dev.fake-pay.complete', $payment->gateway_ref) }}" class="flex gap-3">@csrf
                <button name="outcome" value="pay" class="btn-primary flex-1">Simulate success</button>
                <button name="outcome" value="fail" class="btn-danger flex-1">Simulate failure</button>
            </form>
        </div>
    </div>
</x-store.layout>
