<x-filament-widgets::widget>
    <x-filament::section :heading="__('Community health')">
        <div class="grid gap-6 md:grid-cols-2">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-gray-500">{{ __('Open in queue') }}</dt><dd class="text-2xl font-semibold">{{ $c['open'] }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Received in period') }}</dt><dd class="text-2xl font-semibold">{{ $c['received'] }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Acceptance rate') }}</dt><dd class="text-2xl font-semibold">{{ $c['acceptance_rate'] }}%</dd></div>
                <div><dt class="text-gray-500">{{ __('Median time to resolve') }}</dt><dd class="text-2xl font-semibold">{{ $c['median_hours_to_resolve'] !== null ? __(':h h', ['h' => $c['median_hours_to_resolve']]) : '—' }}</dd></div>
            </dl>
            <div>
                <h3 class="mb-2 text-sm font-medium text-gray-500">{{ __('Top contributors') }}</h3>
                <ol class="space-y-1 text-sm">
                    @forelse ($c['top_contributors'] as $i => $t)
                        <li class="flex justify-between"><span>{{ $i + 1 }}. {{ $t['name'] }}</span><span class="tabular-nums text-gray-500">{{ trans_choice(':count accepted|:count accepted', $t['accepted'], ['count' => $t['accepted']]) }}</span></li>
                    @empty
                        <li class="text-gray-500">{{ __('No accepted contributions in this period.') }}</li>
                    @endforelse
                </ol>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
