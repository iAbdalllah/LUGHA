<x-store.layout :title="$title" :hide-global-errors="true">
    <div class="mx-auto max-w-md px-4 py-14">
        <h1 class="h-display text-3xl">{{ $title }}</h1>
        @isset($intro)<p class="mt-2 text-muted">{{ $intro }}</p>@endisset
        @if (session('status'))<p role="status" class="mt-4 rounded-xl bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</p>@endif
        <div class="card mt-6 p-6">{{ $body }}</div>
        @isset($footer)<div class="mt-5 text-center text-sm text-muted">{{ $footer }}</div>@endisset
    </div>
</x-store.layout>
