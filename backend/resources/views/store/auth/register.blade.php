@component('store.auth.card', ['title' => __('Create your LUGHA account'), 'intro' => __('One account for the store and every LUGHA app.')])
    @slot('body')
        <form method="post" action="{{ route('register') }}" class="space-y-4">@csrf
            <div><label class="label" for="name">{{ __('Name') }}</label><input class="input" id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">@error('name')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="email">{{ __('Email') }}</label><input class="input" dir="ltr" type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email">@error('email')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="password">{{ __('Password') }}</label><input class="input" type="password" id="password" name="password" required autocomplete="new-password">@error('password')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="password_confirmation">{{ __('Confirm password') }}</label><input class="input" type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"></div>
            <label class="flex items-start gap-2 text-sm text-muted"><input type="checkbox" name="terms" value="1" class="mt-0.5 size-4 accent-[#E9B44C]" required> <span>{{ __('I agree to the terms and the') }} <a class="link" href="{{ route('pages.privacy') }}" target="_blank">{{ __('privacy policy') }}</a>.</span></label>
            @error('terms')<p class="text-sm text-danger">{{ $message }}</p>@enderror
            <label class="flex items-start gap-2 text-sm text-muted"><input type="checkbox" name="marketing_opt_in" value="1" class="mt-0.5 size-4 accent-[#E9B44C]"> <span>{{ __('Email me when new games and packages arrive (optional).') }}</span></label>
            <button class="btn-primary w-full">{{ __('Create account') }}</button>
        </form>
    @endslot
    @slot('footer'){{ __('Already have an account?') }} <a class="link" href="{{ route('login') }}">{{ __('Sign in') }}</a>@endslot
@endcomponent
