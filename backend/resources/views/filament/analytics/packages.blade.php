<x-filament-widgets::widget>
    <x-filament::section :heading="__('Package performance')" :description="__('Sorted by revenue in the period. Conversion = units sold ÷ unique page visitors.')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-start text-xs uppercase text-gray-500 dark:border-white/10">
                        <th class="px-3 py-2 text-start">{{ __('Package') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('Visitors') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('Cart adds') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('Sold') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('Revenue') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('Conversion') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('Rating') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('QA') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('Open feedback') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-2"><div class="font-medium">{{ $r['package'] }}</div><div class="text-xs text-gray-500">{{ $r['game'] }}</div></td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($r['views']) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($r['cart_adds']) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ number_format($r['units']) }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ \App\Support\Money::format($r['revenue'], 'SAR', 'en') }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $r['conversion'] !== null ? $r['conversion'].'%' : '—' }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $r['rating'] ? number_format($r['rating'], 1).' ★ ('.$r['rating_count'].')' : '—' }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $r['qa'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-end tabular-nums">{{ $r['open_feedback'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-3 py-6 text-center text-gray-500">{{ __('No packages yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
