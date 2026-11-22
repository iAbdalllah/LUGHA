<x-store.layout :title="__('Contribute')">
    <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-10 sm:px-6 lg:flex-row">
        <x-store.account-nav />
        <div class="min-w-0 max-w-3xl flex-1 space-y-6">
            <h1 class="h-display text-3xl">{{ __('Contribute') }}</h1>
            @if (session('status'))<p role="status" class="text-success">{{ session('status') }}</p>@endif

            @if ($user->isContributor())
                <div class="card p-6">
                    <p class="text-ink-2">{{ __('Thank you for helping. Pick a package you own, find a line, and suggest better Arabic wording. A LUGHA reviewer checks every suggestion before it reaches players.') }}</p>
                    <p class="mt-2 text-sm text-muted">{{ __('Accepted suggestions: :n', ['n' => $acceptedCount]) }} · {{ __('Credited as :name', ['name' => $user->publicName()]) }}</p>
                </div>
                <section>
                    <h2 class="mb-3 text-xl font-semibold">{{ __('Your packages') }}</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @forelse ($packages as $p)
                            <a href="{{ route('contribute.lines', $p) }}" class="card block p-4 hover:border-accent">
                                <span class="block font-semibold">{{ $p->game->title }}</span>
                                <span class="text-sm text-muted">{{ $p->name }}</span>
                            </a>
                        @empty
                            <p class="text-muted">{{ __('Packages you own appear here.') }}</p>
                        @endforelse
                    </div>
                </section>
            @elseif ($user->contributor_status === 'applied')
                <div class="card p-6"><h2 class="text-lg font-semibold">{{ __('Application received') }}</h2><p class="mt-2 text-muted">{{ __('We review applications by hand and will email you when you can start suggesting.') }}</p></div>
            @elseif ($user->contributor_status === 'suspended')
                <div class="card p-6"><p class="text-muted">{{ __('Your contributor access is paused. Contact support if you think this is a mistake.') }}</p></div>
            @else
                <form method="post" action="{{ route('contribute.apply') }}" class="card space-y-4 p-6">@csrf
                    <h2 class="text-lg font-semibold">{{ __('Join the contributor program') }}</h2>
                    <p class="text-muted">{{ __('Contributors suggest better wording for packages they own. Every suggestion is reviewed by our editors; accepted ones ship in the next update and count toward your credits.') }}</p>
                    <div><label class="label" for="display_name">{{ __('Name for credits') }}</label><input class="input" id="display_name" name="display_name" value="{{ old('display_name', explode(' ', $user->name)[0]) }}" required maxlength="60">@error('display_name')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror</div>
                    <label class="flex items-start gap-2 text-sm text-ink-2"><input type="checkbox" name="accept_terms" value="1" class="mt-1" required> <span>{{ __('I agree that my suggestions may be used, edited and published by LUGHA in its packages, without payment, and that I will not submit text I copied from elsewhere.') }}</span></label>
                    @error('accept_terms')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                    <button class="btn-primary">{{ __('Apply') }}</button>
                </form>
            @endif

            @if ($mine->isNotEmpty())
                <section>
                    <h2 class="mb-3 text-xl font-semibold">{{ __('Your reports and suggestions') }}</h2>
                    <ul class="space-y-2">
                        @foreach ($mine as $f)
                            <li class="card p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-sm text-muted">{{ $f->package?->name }} · {{ __('community.kind.'.$f->kind) }} · {{ __('community.reason.'.$f->reason) }}</span>
                                    <span @class(['chip', 'text-success' => $f->status === 'accepted', 'text-danger' => $f->status === 'rejected'])>{{ __('community.status.'.$f->status) }}</span>
                                </div>
                                <p class="mt-2 text-sm text-faint" dir="ltr">{{ $f->translation->source_text }}</p>
                                @if ($f->suggested_text)<p class="mt-1">{{ $f->suggested_text }}</p>@endif
                                @if ($f->resolution_note)<p class="mt-1 text-sm text-muted">{{ __('Reviewer:') }} {{ $f->resolution_note }}</p>@endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</x-store.layout>
