<section class="mx-auto max-w-7xl px-4 py-14 sm:px-6" aria-labelledby="how-h">
    <h2 id="how-h" class="h-display text-3xl font-semibold">{{ __('How LUGHA works') }}</h2>
    <p class="mb-8 mt-1 text-muted">{{ __('Four steps, once.') }}</p>
    <ol class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [__('Choose a package'), __('Compare dialogue, story and full localization packages for each game.')],
            [__('Pay securely'), __('mada, Apple Pay, Visa and Mastercard, in Saudi riyals including VAT.')],
            [__('Install on your device'), __('Sign in to the LUGHA app and install the package in one click.')],
            [__('Play'), __('The translation appears over the game or on your TV, and the package updates itself.')],
        ] as $i => [$h, $p])
            <li class="rounded-2xl border border-line bg-raised p-6">
                <span class="font-mono text-sm text-accent">0{{ $i + 1 }}</span>
                <h3 class="mb-1.5 mt-2.5 text-lg font-semibold">{{ $h }}</h3>
                <p class="text-[15px] text-muted">{{ $p }}</p>
            </li>
        @endforeach
    </ol>
</section>
