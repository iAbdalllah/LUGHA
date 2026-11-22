<x-store.layout :title="__('How LUGHA works')">
    <x-store.page-header :title="__('How LUGHA works')" :subtitle="__('Pre-translated, reviewed Arabic text — matched to what\'s on your screen, on your own device.')" />
    @include('store.partials.how-steps')
    <section class="mx-auto max-w-3xl space-y-4 px-4 pb-10 text-ink-2 sm:px-6">
        <h2 class="text-xl font-semibold text-ink">{{ __('What happens on your device') }}</h2>
        <p>{{ __('The LUGHA app reads the subtitle area of your screen, finds the matching line in the package you installed, and shows the reviewed Arabic translation. Everything happens locally: screenshots and text never leave your device.') }}</p>
        <h2 class="text-xl font-semibold text-ink">{{ __('Human translations, not machine translation') }}</h2>
        <p>{{ __('Every line in a LUGHA package is written and reviewed by translators before it is published. LUGHA does not use automatic or AI translation.') }}</p>
    </section>
</x-store.layout>
