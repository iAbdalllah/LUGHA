<x-store.layout :title="__('Contribute').' — '.$package->name">
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-10 sm:px-6">
        <div>
            <nav class="text-sm text-faint"><a class="hover:text-ink" href="{{ route('contribute.index') }}">{{ __('Contribute') }}</a> / {{ $package->game->title }}</nav>
            <h1 class="h-display mt-2 text-3xl">{{ $package->name }}</h1>
            <p class="mt-1 text-muted">{{ __('Suggest better wording for any line. Keep names, numbers and the meaning; follow the package style.') }}</p>
        </div>
        @if (session('status'))<p role="status" class="text-success">{{ session('status') }}</p>@endif
        @if ($errors->any())<p role="alert" class="text-danger">{{ $errors->first() }}</p>@endif

        <form method="get" class="flex flex-wrap gap-3">
            <input class="input flex-[1_1_260px]" name="q" value="{{ $q }}" placeholder="{{ __('Search English or Arabic') }}">
            <select class="input w-auto" name="category"><option value="">{{ __('All categories') }}</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected((string) $category === (string) $c->id)>{{ $c->name }}</option>@endforeach</select>
            <button class="btn-light">{{ __('Search') }}</button>
        </form>

        <ul class="space-y-3">
            @forelse ($lines as $line)
                <li class="card p-4">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-faint"><span class="chip">{{ $line->category->name }}</span>@if ($line->speaker)<span dir="auto">{{ $line->speaker }}</span>@endif<span dir="ltr">#{{ $line->id }}</span></div>
                    <p class="mt-2 text-sm text-muted" dir="ltr">{{ $line->source_text }}</p>
                    <p class="mt-1 text-lg">{{ $line->target_text }}</p>
                    @if (in_array($line->id, $open, true))
                        <p class="mt-2 text-sm text-accent">{{ __('Your suggestion is waiting for review.') }}</p>
                    @else
                        <details class="mt-2">
                            <summary class="cursor-pointer text-sm text-accent">{{ __('Suggest better wording') }}</summary>
                            <form method="post" action="{{ route('contribute.suggest', $package) }}" class="mt-3 space-y-3">@csrf
                                <input type="hidden" name="entry_id" value="{{ $line->id }}">
                                <textarea class="input" name="suggested_text" rows="2" required maxlength="1000">{{ $line->target_text }}</textarea>
                                <div class="flex flex-wrap gap-3">
                                    <select class="input w-auto" name="reason">@foreach (\App\Models\TranslationFeedback::REASONS as $r)<option value="{{ $r }}">{{ __('community.reason.'.$r) }}</option>@endforeach</select>
                                    <input class="input flex-1" name="note" maxlength="500" placeholder="{{ __('Why? (optional)') }}">
                                    <button class="btn-primary">{{ __('Send') }}</button>
                                </div>
                            </form>
                        </details>
                    @endif
                </li>
            @empty
                <li class="card p-8 text-center text-muted">{{ __('No lines match.') }}</li>
            @endforelse
        </ul>
        {{ $lines->links() }}
    </div>
</x-store.layout>
