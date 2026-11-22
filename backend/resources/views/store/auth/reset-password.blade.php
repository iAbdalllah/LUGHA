@component('store.auth.card', ['title' => __('Choose a new password')])
    @slot('body')
        <form method="post" action="{{ route('password.update') }}" class="space-y-4">@csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">
            <div><label class="label" for="email">{{ __('Email') }}</label><input class="input" dir="ltr" type="email" id="email" name="email" value="{{ old('email', $request->email) }}" required>@error('email')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="password">{{ __('New password') }}</label><input class="input" type="password" id="password" name="password" required autocomplete="new-password">@error('password')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="password_confirmation">{{ __('Confirm new password') }}</label><input class="input" type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"></div>
            <button class="btn-primary w-full">{{ __('Save new password') }}</button>
        </form>
    @endslot
@endcomponent
