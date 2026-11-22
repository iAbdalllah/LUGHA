<x-store.layout :title="__('Support center')">
    <x-store.page-header :title="__('Support center')" :subtitle="__('Questions about a purchase, a download or a translation? We\'re here.')" />
    <div class="mx-auto flex max-w-7xl flex-wrap gap-8 px-4 py-8 sm:px-6">
        <div class="min-w-0 flex-[999_1_520px] space-y-3">
            <h2 class="text-xl font-semibold">{{ __('Common questions') }}</h2>
            @foreach ([
                [__('Do I need the internet while playing?'), __('No. Once a package is installed in the LUGHA app, translations work offline.')],
                [__('Does LUGHA change my game files?'), __('No. LUGHA reads the text on your screen and shows the Arabic translation in its own window. Your game is untouched.')],
                [__('Can I upgrade to a bigger package?'), __('Yes. On the game page, packages above the one you own show an upgrade price — you only pay the difference.')],
                [__('How do refunds work?'), __('See the refund policy. Approved refunds go back to your original payment method and remove the package from your library.')],
            ] as [$q, $a])
                <details class="card group p-5"><summary class="cursor-pointer list-none font-semibold">{{ $q }}</summary><p class="mt-2 text-ink-2">{{ $a }}</p></details>
            @endforeach
        </div>
        <div class="min-w-0 flex-[1_1_380px] space-y-6">
            @auth
                <form method="post" action="{{ route('support.store') }}" class="card space-y-4 p-6">@csrf
                    <h2 class="text-lg font-semibold">{{ __('Contact us') }}</h2>
                    <div><label class="label" for="category">{{ __('Topic') }}</label>
                        <select class="input" id="category" name="category">@foreach (\App\Models\SupportTicket::CATEGORIES as $c)<option value="{{ $c }}" @selected(old('category') === $c)>{{ __('support.category.'.$c) }}</option>@endforeach</select></div>
                    @if ($orders->isNotEmpty())
                        <div><label class="label" for="order">{{ __('Related order (optional)') }}</label>
                            <select class="input" id="order" name="order"><option value="">—</option>@foreach ($orders as $o)<option value="{{ $o->public_id }}" @selected(old('order') === $o->public_id)>{{ $o->number }}</option>@endforeach</select></div>
                    @endif
                    <div><label class="label" for="subject">{{ __('Subject') }}</label><input class="input" id="subject" name="subject" required maxlength="200" value="{{ old('subject') }}"></div>
                    <div><label class="label" for="body">{{ __('Message') }}</label><textarea class="input h-32 py-2.5" id="body" name="body" required maxlength="5000">{{ old('body') }}</textarea></div>
                    <button class="btn-primary w-full">{{ __('Send') }}</button>
                </form>
                @if ($tickets->isNotEmpty())
                    <section><h2 class="mb-3 text-lg font-semibold">{{ __('Your requests') }}</h2>
                        <ul class="card divide-y divide-line">@foreach ($tickets as $t)
                            <li><a href="{{ route('support.show', $t) }}" class="flex justify-between gap-3 px-5 py-3.5 hover:bg-surface-2"><span>{{ $t->subject }}</span><span class="chip">{{ __('support.status.'.$t->status) }}</span></a></li>
                        @endforeach</ul></section>
                @endif
            @else
                <div class="card p-6"><p class="text-ink-2">{{ __('Sign in to send us a message about your account or purchases.') }}</p><a href="{{ route('login') }}" class="btn-light mt-4">{{ __('Sign in') }}</a></div>
            @endauth
        </div>
    </div>
</x-store.layout>
