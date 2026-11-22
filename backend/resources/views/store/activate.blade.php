<x-store.layout :title="__('Sign in a device')" :hide-global-errors="true">
    <div class="mx-auto max-w-md px-4 py-14">
        <h1 class="h-display text-3xl">{{ __('Sign in a device') }}</h1>
        <p class="mt-2 text-muted">{{ __('Enter the code shown on your TV or in the LUGHA app. No passwords on your TV.') }}</p>
        @if (session('status'))<p role="status" class="mt-4 rounded-xl bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</p>@endif
        <form method="post" action="{{ route('activate.decide') }}" class="card mt-6 space-y-4 p-6">@csrf
            <div><label class="label" for="code">{{ __('Code') }}</label>
                <input class="input text-center font-mono text-2xl uppercase tracking-[0.3em]" dir="ltr" id="code" name="code" required maxlength="12" autocomplete="off" value="{{ old('code', $code) }}" placeholder="ABC-123">
                @error('code')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
            @if ($pending)
                <p class="rounded-xl bg-surface-2 px-4 py-3 text-sm">{{ __(':name wants to sign in to your account.', ['name' => $pending->client_name]) }}</p>
            @endif
            <div class="flex gap-3">
                <button name="decision" value="approve" class="btn-primary flex-1">{{ __('Approve') }}</button>
                <button name="decision" value="deny" class="btn-ghost">{{ __('Decline') }}</button>
            </div>
            <p class="text-xs text-faint">{{ __('Only approve a code you see on your own device right now.') }}</p>
        </form>
    </div>
</x-store.layout>
