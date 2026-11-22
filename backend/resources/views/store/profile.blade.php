<x-store.layout :title="__('Profile & security')" :hide-global-errors="true">
    <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-10 sm:px-6 lg:flex-row">
        <x-store.account-nav />
        <div class="min-w-0 max-w-2xl flex-1 space-y-6">
            <h1 class="h-display text-3xl">{{ __('Profile & security') }}</h1>
            @if (session('status') === 'profile-information-updated')<p role="status" class="text-success">{{ __('Profile saved.') }}</p>@endif
            @if (session('status') === 'password-updated')<p role="status" class="text-success">{{ __('Password changed.') }}</p>@endif
            <form method="post" action="{{ route('user-profile-information.update') }}" class="card space-y-4 p-6">@csrf @method('put')
                <h2 class="text-lg font-semibold">{{ __('Account') }}</h2>
                <div><label class="label" for="name">{{ __('Name') }}</label><input class="input" id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="120">@error('name', 'updateProfileInformation')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="email">{{ __('Email') }}</label><input class="input" dir="ltr" type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required>@error('email', 'updateProfileInformation')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-faint">{{ __('Changing your email asks you to verify the new address.') }}</p></div>
                <button class="btn-light">{{ __('Save') }}</button>
            </form>
            <form method="post" action="{{ route('user-password.update') }}" class="card space-y-4 p-6">@csrf @method('put')
                <h2 class="text-lg font-semibold">{{ __('Change password') }}</h2>
                <div><label class="label" for="current_password">{{ __('Current password') }}</label><input class="input" type="password" id="current_password" name="current_password" required autocomplete="current-password">@error('current_password', 'updatePassword')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="password">{{ __('New password') }}</label><input class="input" type="password" id="password" name="password" required autocomplete="new-password">@error('password', 'updatePassword')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="password_confirmation">{{ __('Confirm new password') }}</label><input class="input" type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"></div>
                <button class="btn-light">{{ __('Change password') }}</button>
            </form>
        </div>
    </div>
</x-store.layout>
