@php($kindLabel = ['macos' => 'macOS', 'windows' => 'Windows', 'android' => 'Android', 'ios' => 'iOS', 'android_tv' => 'Android TV'])
<x-store.layout :title="__('Devices')">
    <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-10 sm:px-6 lg:flex-row">
        <x-store.account-nav />
        <div class="min-w-0 flex-1 space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div><h1 class="h-display text-3xl">{{ __('Devices') }}</h1>
                    <p class="mt-1 text-muted">{{ __('Apps: :a of :b · TVs: :c of :d', ['a' => $devices->where('kind', '!=', 'android_tv')->count(), 'b' => $limits['app'], 'c' => $devices->where('kind', 'android_tv')->count(), 'd' => $limits['tv']]) }}</p></div>
                <a href="{{ route('activate') }}" class="btn-ghost">{{ __('Sign in a new device') }}</a>
            </div>
            @if ($devices->isEmpty())
                <p class="card p-8 text-center text-muted">{{ __('No devices yet. Sign in to the LUGHA app on your computer or TV.') }}</p>
            @else
            <div class="relative overflow-x-auto rounded-2xl border border-line">
                <table class="w-full min-w-[560px] text-[15px]">
                    <thead class="bg-surface"><tr>
                        <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Device') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Type') }}</th>
                        <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Last seen') }}</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($devices as $d)
                        <tr class="border-t border-line">
                            <td class="px-4 py-3">{{ $d->name }} @if ($d->app_version)<span class="text-xs text-faint" dir="ltr">· v{{ $d->app_version }}</span>@endif</td>
                            <td class="px-4 py-3 text-muted" dir="ltr">{{ $kindLabel[$d->kind] ?? $d->kind }}</td>
                            <td class="px-4 py-3 text-muted">{{ $d->last_seen_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-4 py-3 text-end">
                                <form method="post" action="{{ route('devices.destroy', $d) }}" onsubmit="return confirm(@js(__('Remove :name?', ['name' => $d->name])))">@csrf @method('delete')
                                    <button class="btn-danger h-9 px-3 text-sm">{{ __('Remove') }}</button></form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
</x-store.layout>
