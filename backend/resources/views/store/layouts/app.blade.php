@php
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
    $other = $locale === 'ar' ? 'en' : 'ar';
    $route = request()->route();
    $switchUrl = $route && $route->getName() && str_contains($route->uri(), '{locale}')
        ? route($route->getName(), array_merge($route->originalParameters(), ['locale' => $other]))
        : url($other);
    $cartCount = auth()->check() ? auth()->user()->cartItems()->count() : 0;
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ __('LUGHA') }}</title>
    <meta name="description" content="{{ $description ?? __('Reviewed Arabic localization packages for your games, shown as subtitles while you play.') }}">
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-ground text-ink antialiased print:bg-white print:text-black">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 btn-primary">{{ __('Skip to content') }}</a>

<header class="border-b border-line print:hidden">
    <div class="relative mx-auto flex max-w-7xl flex-wrap items-center gap-4 px-4 py-3 sm:px-6 lg:gap-6">
        <a href="{{ route('home') }}" class="flex items-center gap-2.5" aria-label="{{ __('LUGHA home') }}">
            <span class="grid size-9 place-items-center rounded-[10px] bg-gradient-to-br from-accent to-[#B9852A] text-lg font-bold text-accent-ink" style="font-family:var(--font-display)">ل</span>
            <span class="h-display text-xl">{{ $locale === 'ar' ? 'لغة' : 'LUGHA' }}</span>
            @if($locale === 'ar')<span class="hidden text-xs tracking-[0.18em] text-muted sm:inline">LUGHA</span>@endif
        </a>
        <nav aria-label="{{ __('Main') }}" class="order-3 flex w-full gap-1 overflow-x-auto [scrollbar-width:none] text-sm sm:order-none sm:w-auto">
            @foreach ([['games.index', __('Store')], ['library', __('My library')], ['pages.how', __('How it works')], ['support.index', __('Support')]] as [$name, $label])
                <a href="{{ route($name) }}" @class(['whitespace-nowrap rounded-lg px-3.5 py-2', 'bg-surface-2 text-ink' => request()->routeIs($name.'*'), 'text-muted hover:text-ink' => ! request()->routeIs($name.'*')])>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="ms-auto flex items-center gap-2">
            <form action="{{ route('games.index') }}" method="get" role="search" class="hidden md:block">
                <label class="flex h-11 w-64 items-center gap-2 rounded-xl border border-[#2A2E37] bg-surface px-3">
                    <svg class="size-4 shrink-0 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <span class="sr-only">{{ __('Search games') }}</span>
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search games…') }}" class="w-full bg-transparent text-sm outline-none placeholder:text-faint">
                </label>
            </form>
            <a href="{{ $switchUrl }}" hreflang="{{ $other }}" lang="{{ $other }}" class="btn-ghost h-11 px-3 text-sm font-medium">{{ $other === 'ar' ? 'العربية' : 'English' }}</a>
            @auth
                <a href="{{ route('cart.show') }}" class="btn-ghost relative h-11 w-11 px-0" aria-label="{{ trans_choice('Cart, :count item|Cart, :count items', $cartCount, ['count' => $cartCount]) }}">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>
                    @if($cartCount)<span class="absolute -top-1.5 -start-1.5 grid h-5 min-w-5 place-items-center rounded-full bg-accent px-1 text-[11px] font-bold text-accent-ink">{{ $cartCount }}</span>@endif
                </a>
                <details class="max-sm:static sm:relative">
                    <summary class="btn-ghost h-11 cursor-pointer list-none px-3 text-sm">{{ \Illuminate\Support\Str::limit(auth()->user()->name, 14) }}</summary>
                    <div class="absolute z-20 mt-2 rounded-xl max-sm:inset-x-4 sm:end-0 sm:w-56 border border-line bg-surface p-1.5 text-sm shadow-2xl">
                        <a class="block rounded-lg px-3 py-2 hover:bg-surface-2" href="{{ route('library') }}">{{ __('My library') }}</a>
                        <a class="block rounded-lg px-3 py-2 hover:bg-surface-2" href="{{ route('orders.index') }}">{{ __('Purchases & invoices') }}</a>
                        <a class="block rounded-lg px-3 py-2 hover:bg-surface-2" href="{{ route('downloads') }}">{{ __('Download center') }}</a>
                        <a class="block rounded-lg px-3 py-2 hover:bg-surface-2" href="{{ route('gift.show') }}">{{ __('Redeem a gift code') }}</a>
                        <a class="block rounded-lg px-3 py-2 hover:bg-surface-2" href="{{ route('profile') }}">{{ __('Profile & security') }}</a>
                        @if(auth()->user()->is_staff)<a class="block rounded-lg px-3 py-2 hover:bg-surface-2" href="{{ url('/admin') }}">{{ __('Admin') }}</a>@endif
                        <form method="post" action="{{ route('logout') }}">@csrf<button class="block w-full rounded-lg px-3 py-2 text-start text-danger hover:bg-surface-2">{{ __('Sign out') }}</button></form>
                    </div>
                </details>
            @else
                <a href="{{ route('login') }}" class="btn-light h-11 px-4 text-sm">{{ __('Sign in') }}</a>
            @endauth
        </div>
    </div>
</header>

<main id="main">
    @if (session('status'))
        <div class="mx-auto mt-6 max-w-7xl px-4 sm:px-6"><p role="status" class="rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</p></div>
    @endif
    @if ($errors->any() && ! ($hideGlobalErrors ?? false))
        <div class="mx-auto mt-6 max-w-7xl px-4 sm:px-6"><div role="alert" class="rounded-xl border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div></div>
    @endif
    {{ $slot }}
</main>

<footer class="mt-16 border-t border-line print:hidden">
    <div class="mx-auto flex max-w-7xl flex-wrap justify-between gap-8 px-4 py-10 text-sm text-faint sm:px-6">
        <div class="max-w-sm space-y-1.5">
            <p class="h-display text-lg text-ink">{{ $locale === 'ar' ? 'لغة' : 'LUGHA' }}</p>
            <p>{{ __('Every translation is published with the rights holder\'s permission. LUGHA never modifies or distributes game files.') }}</p>
        </div>
        <nav aria-label="{{ __('Footer') }}" class="flex flex-wrap gap-5">
            <a class="hover:text-ink" href="{{ route('pages.refunds') }}">{{ __('Refund policy') }}</a>
            <a class="hover:text-ink" href="{{ route('pages.privacy') }}">{{ __('Privacy') }}</a>
            <a class="hover:text-ink" href="{{ route('support.index') }}">{{ __('Support center') }}</a>
        </nav>
    </div>
</footer>
</body>
</html>
