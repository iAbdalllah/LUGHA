@props(['title', 'subtitle' => null])
<div class="mx-auto max-w-7xl px-4 pt-10 sm:px-6">
    <h1 class="h-display text-3xl sm:text-4xl">{{ $title }}</h1>
    @if ($subtitle)<p class="mt-1.5 text-muted">{{ $subtitle }}</p>@endif
    {{ $slot }}
</div>
