@component('store.auth.card', ['title' => __('Reset your password'), 'intro' => __('We\'ll email you a link to choose a new password.')])
    @slot('body')
        <form method="post" action="{{ route('password.email') }}" class="space-y-4">@csrf
            <div><label class="label" for="email">{{ __('Email') }}</label><input class="input" dir="ltr" type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>@error('email')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
            <button class="btn-primary w-full">{{ __('Send reset link') }}</button>
        </form>
    @endslot
    @slot('footer')<a class="link" href="{{ route('login') }}">{{ __('Back to sign in') }}</a>@endslot
@endcomponent
