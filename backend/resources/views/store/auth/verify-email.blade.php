@component('store.auth.card', ['title' => __('Verify your email'), 'intro' => __('We sent a verification link to your email. Verify it to buy packages.')])
    @slot('body')
        @if (session('status') === 'verification-link-sent')<p class="mb-4 text-sm text-success">{{ __('A new link is on its way.') }}</p>@endif
        <form method="post" action="{{ route('verification.send') }}">@csrf<button class="btn-primary w-full">{{ __('Resend the link') }}</button></form>
        <form method="post" action="{{ route('logout') }}" class="mt-3 text-center">@csrf<button class="text-sm text-muted hover:text-ink">{{ __('Sign out') }}</button></form>
    @endslot
@endcomponent
