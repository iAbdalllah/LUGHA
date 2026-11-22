<nav aria-label="{{ __('Account') }}" class="flex gap-1 overflow-x-auto [scrollbar-width:none] text-sm lg:w-56 lg:shrink-0 lg:flex-col">
    @foreach ([['library', __('My library')], ['orders.index', __('Purchases & invoices')], ['downloads', __('Download center')], ['devices', __('Devices')], ['gift.show', __('Redeem a gift code')], ['profile', __('Profile & security')]] as [$name, $label])
        <a href="{{ route($name) }}" @class(['whitespace-nowrap rounded-xl px-3.5 py-2.5', 'bg-surface font-semibold text-ink' => request()->routeIs($name), 'text-muted hover:text-ink' => ! request()->routeIs($name)])>{{ $label }}</a>
    @endforeach
</nav>
