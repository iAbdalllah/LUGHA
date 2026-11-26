{{-- The LUGHA apps, for everyone (no sign-in): installers are served from public/ (mac.dmg, windows.exe, tv.apk). --}}
<x-store.layout :title="__('Download LUGHA')">
    <x-store.page-header :title="__('Download LUGHA')" :subtitle="__('Install the app on the device you play on, sign in with your LUGHA account, and your packages appear automatically.')" />
    <section class="mx-auto grid max-w-5xl gap-4 px-4 pb-12 sm:grid-cols-2 sm:px-6">
        <div class="card flex flex-col p-6">
            <p class="text-lg font-semibold" dir="ltr">macOS</p>
            <p class="mt-1 flex-1 text-sm text-ink-2">{{ __('macOS 13 or later on a Mac with Apple silicon (M1 or newer). Open the file and drag LUGHA to Applications.') }}</p>
            <a class="btn-primary mt-4 self-start" href="{{ asset('mac.dmg') }}" download>{{ __('Download for Mac') }}</a>
        </div>
        <div class="card flex flex-col p-6">
            <p class="text-lg font-semibold" dir="ltr">Windows</p>
            <p class="mt-1 flex-1 text-sm text-ink-2">{{ __('Windows 10 or 11 (64-bit). If Windows SmartScreen appears, choose “More info” then “Run anyway”.') }}</p>
            <a class="btn-primary mt-4 self-start" href="{{ asset('windows.exe') }}" download>{{ __('Download for Windows') }}</a>
        </div>
        <div class="card flex flex-col p-6 sm:col-span-2">
            <p class="text-lg font-semibold" dir="ltr">Android TV · Google TV</p>
            <p class="mt-1 text-sm text-ink-2">{{ __('Install the free Downloader app from your TV\'s app store, open it, and enter this code:') }}</p>
            <p class="mt-3 font-mono text-4xl font-bold tracking-widest" dir="ltr">2948953</p>
            <p class="mt-3 text-sm text-muted">{{ __('Then allow Downloader to install apps when the TV asks. Or download the file directly:') }}
                <a class="underline" href="{{ asset('tv.apk') }}" dir="ltr">{{ parse_url(config('app.url'), PHP_URL_HOST) }}/tv.apk</a></p>
        </div>
        <div class="card flex flex-col p-6 opacity-80">
            <p class="text-lg font-semibold" dir="ltr">iPhone · iPad</p>
            <p class="mt-1 text-sm text-ink-2">{{ __('Coming soon to the App Store.') }}</p>
        </div>
        <div class="card flex flex-col p-6 opacity-80">
            <p class="text-lg font-semibold" dir="ltr">Android</p>
            <p class="mt-1 text-sm text-ink-2">{{ __('Coming soon to Google Play.') }}</p>
        </div>
    </section>
</x-store.layout>
