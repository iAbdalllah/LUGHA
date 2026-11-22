<x-store.layout :title="__('Download center')">
    <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-10 sm:px-6 lg:flex-row">
        <x-store.account-nav />
        <div class="min-w-0 flex-1 space-y-6">
            <h1 class="h-display text-3xl">{{ __('Download center') }}</h1>
            <section class="card p-6">
                <h2 class="text-lg font-semibold">{{ __('Packages install through the LUGHA app') }}</h2>
                <p class="mt-2 text-ink-2">{{ __('For security, packages are encrypted and licensed to your devices, so they are installed from the LUGHA desktop app rather than downloaded here. Sign in to the app with this account and your library appears automatically.') }}</p>
                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    @foreach (['macOS', 'Windows', 'Android TV'] as $app)
                        <div class="rounded-xl border border-line p-4"><p class="font-semibold" dir="ltr">{{ $app }}</p><p class="text-sm text-muted">{{ __('Coming soon — we\'ll email you when it\'s ready.') }}</p></div>
                    @endforeach
                </div>
            </section>
            <section>
                <h2 class="mb-3 text-lg font-semibold">{{ __('Your packages') }}</h2>
                <ul class="card divide-y divide-line">
                    @forelse ($entitlements as $e)
                        @php($latest = $e->package->versions->first(fn ($v) => $e->covers($v)))
                        <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3.5"><span>{{ $e->package->game->title }} — {{ $e->package->name }}</span>
                            <span dir="ltr" class="font-mono text-sm text-muted">{{ $latest ? 'v'.$latest->version : '—' }}</span></li>
                    @empty
                        <li class="px-5 py-6 text-center text-muted">{{ __('No packages yet.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-store.layout>
