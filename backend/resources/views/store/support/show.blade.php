<x-store.layout :title="$ticket->subject">
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-10 sm:px-6">
        <a href="{{ route('support.index') }}" class="link text-sm"><span class="flip-rtl inline-block" aria-hidden="true">←</span> {{ __('Support center') }}</a>
        <div class="flex flex-wrap items-center justify-between gap-3"><h1 class="h-display text-3xl">{{ $ticket->subject }}</h1><span class="chip">{{ __('support.status.'.$ticket->status) }}</span></div>
        <ol class="space-y-3">
            @foreach ($ticket->messages as $msg)
                <li @class(['card p-5', 'border-accent/30' => $msg->author_id !== $ticket->user_id])>
                    <p class="mb-2 text-sm text-faint">{{ $msg->author_id === $ticket->user_id ? __('You') : __('LUGHA support') }} · {{ $msg->created_at->diffForHumans() }}</p>
                    <p class="whitespace-pre-line">{{ $msg->body }}</p>
                </li>
            @endforeach
        </ol>
        @if ($ticket->status !== 'closed')
            <form method="post" action="{{ route('support.reply', $ticket) }}" class="card space-y-3 p-5">@csrf
                <label class="label" for="body">{{ __('Reply') }}</label><textarea class="input h-28 py-2.5" id="body" name="body" required maxlength="5000"></textarea>
                <button class="btn-light">{{ __('Send') }}</button>
            </form>
        @endif
    </div>
</x-store.layout>
