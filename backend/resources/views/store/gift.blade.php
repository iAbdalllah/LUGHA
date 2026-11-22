<x-store.layout :title="__('Redeem a gift code')">
    <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-10 sm:px-6 lg:flex-row">
        <x-store.account-nav />
        <div class="min-w-0 max-w-lg flex-1">
            <h1 class="h-display text-3xl">{{ __('Redeem a gift code') }}</h1>
            <p class="mt-2 text-muted">{{ __('Enter the code you received. The package is added to your library straight away.') }}</p>
            <form method="post" action="{{ route('gift.redeem') }}" class="card mt-6 space-y-4 p-6">@csrf
                <div><label class="label" for="code">{{ __('Gift code') }}</label><input class="input font-mono uppercase tracking-widest" dir="ltr" id="code" name="code" required maxlength="40" value="{{ old('code') }}" placeholder="XXXX-XXXX-XXXX-XXXX" autocomplete="off"></div>
                <button class="btn-primary w-full">{{ __('Redeem') }}</button>
            </form>
        </div>
    </div>
</x-store.layout>
