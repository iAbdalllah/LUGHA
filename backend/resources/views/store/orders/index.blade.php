<x-store.layout :title="__('Purchases & invoices')">
    <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-10 sm:px-6 lg:flex-row">
        <x-store.account-nav />
        <div class="min-w-0 flex-1">
            <h1 class="h-display mb-6 text-3xl">{{ __('Purchases & invoices') }}</h1>
            @if ($orders->isEmpty())
                <p class="card p-8 text-center text-muted">{{ __('No purchases yet.') }}</p>
            @else
            <div class="relative overflow-x-auto rounded-2xl border border-line">
                <table class="w-full min-w-[640px] text-[15px]">
                    <thead class="bg-surface"><tr>
                        <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Order') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Items') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Total') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Status') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Date') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($orders as $o)
                        <tr class="border-t border-line">
                            <td class="px-4 py-3"><a class="link font-mono" dir="ltr" href="{{ route('orders.show', $o) }}">{{ $o->number ?? substr($o->public_id, -8) }}</a></td>
                            <td class="px-4 py-3 text-ink-2">{{ $o->items->map(fn ($i) => $i->package->name)->implode('، ') }}</td>
                            <td class="px-4 py-3">{{ \App\Support\Money::format($o->total_amount, $o->currency) }}</td>
                            <td class="px-4 py-3"><span class="chip">{{ $o->status->label() }}</span></td>
                            <td class="px-4 py-3 text-muted">{{ $o->created_at->translatedFormat('j M Y') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-6">{{ $orders->links() }}</div>
            @endif
        </div>
    </div>
</x-store.layout>
