<x-store.layout :title="__('Game store')">
    <x-store.page-header :title="__('Game store')" :subtitle="__('Every game with a reviewed Arabic localization package')">
        <form method="get" class="mt-6 flex flex-wrap items-end gap-3" role="search">
            <div class="min-w-56 flex-1"><label class="label" for="q">{{ __('Search') }}</label><input id="q" class="input" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('Game title…') }}"></div>
            <div><label class="label" for="platform">{{ __('Platform') }}</label>
                <select id="platform" name="platform" class="input w-48"><option value="">{{ __('All platforms') }}</option>@foreach ($platforms as $p)<option value="{{ $p->slug }}" @selected(($filters['platform'] ?? '') === $p->slug)>{{ $p->name }}</option>@endforeach</select></div>
            <div><label class="label" for="sort">{{ __('Sort') }}</label>
                <select id="sort" name="sort" class="input w-44">@foreach (['featured' => __('Featured'), 'new' => __('Newest'), 'updated' => __('Recently updated'), 'title' => __('Title')] as $k => $v)<option value="{{ $k }}" @selected(($filters['sort'] ?? 'featured') === $k)>{{ $v }}</option>@endforeach</select></div>
            <button class="btn-light">{{ __('Apply') }}</button>
        </form>
    </x-store.page-header>
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        @if ($games->isEmpty())
            <p class="card p-8 text-center text-muted">{{ __('No games match your search.') }}</p>
        @else
            <div class="grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-3 lg:grid-cols-4">@foreach ($games as $game)<x-store.game-card :game="$game" />@endforeach</div>
            <div class="mt-8">{{ $games->links() }}</div>
        @endif
    </div>
</x-store.layout>
