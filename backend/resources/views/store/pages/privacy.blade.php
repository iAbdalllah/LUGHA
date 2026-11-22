<x-store.layout :title="__('Privacy')">
    <x-store.page-header :title="__('Privacy')" />
    <div class="mx-auto max-w-3xl space-y-4 px-4 py-8 text-ink-2 sm:px-6">
        <p class="rounded-xl border border-accent/30 bg-accent/10 p-4 text-sm text-accent">{{ __('[Draft — the full privacy policy must be reviewed by counsel before launch.]') }}</p>
        <p>{{ __('We collect what we need to run your account and purchases: your name, email, orders and the devices you sign in on. Payment card details are handled by our payment provider and never reach LUGHA.') }}</p>
        <p>{{ __('The LUGHA apps process your screen locally. We do not upload screenshots or the text of your games.') }}</p>
    </div>
</x-store.layout>
