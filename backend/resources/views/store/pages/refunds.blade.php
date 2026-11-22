<x-store.layout :title="__('Refund policy')">
    <x-store.page-header :title="__('Refund policy')" />
    <div class="mx-auto max-w-3xl space-y-4 px-4 py-8 text-ink-2 sm:px-6">
        <p class="rounded-xl border border-accent/30 bg-accent/10 p-4 text-sm text-accent">{{ __('[Draft — the refund window and conditions must be confirmed by LUGHA before launch.]') }}</p>
        <p>{{ __('You can ask for a refund from your order page or through support. Approved refunds are returned to the original payment method and the package is removed from your library and devices.') }}</p>
        <p>{{ __('Gift purchases can be refunded while the gift code has not been redeemed.') }}</p>
    </div>
</x-store.layout>
