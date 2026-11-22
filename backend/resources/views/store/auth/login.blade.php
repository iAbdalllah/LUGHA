@component('store.auth.card', ['title' => __('Sign in')])
    @slot('body')
        <form method="post" action="{{ route('login') }}" class="space-y-4">@csrf
            <div><label class="label" for="email">{{ __('Email') }}</label><input class="input" dir="ltr" type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">@error('email')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
            <div><div class="flex justify-between"><label class="label" for="password">{{ __('Password') }}</label><a class="link text-sm" href="{{ route('password.request') }}">{{ __('Forgot password?') }}</a></div>
                <input class="input" type="password" id="password" name="password" required autocomplete="current-password">@error('password')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
            <label class="flex items-center gap-2 text-sm text-muted"><input type="checkbox" name="remember" class="size-4 accent-[#E9B44C]"> {{ __('Keep me signed in') }}</label>
            <button class="btn-primary w-full">{{ __('Sign in') }}</button>
        </form>
    @endslot
    @slot('footer'){{ __('New to LUGHA?') }} <a class="link" href="{{ route('register') }}">{{ __('Create an account') }}</a>@endslot
@endcomponent
