<?php

namespace App\Filament\Analytics;

use Filament\Widgets\ChartWidget;

class DevicesChart extends ChartWidget
{
    use UsesMetrics;

    protected static bool $isLazy = false;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return __('Active devices by platform');
    }

    public function getDescription(): ?string
    {
        return __(':n package downloads in this period', ['n' => $this->metrics()->usage()['downloads']]);
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $d = $this->metrics()->usage()['devices'];
        $names = ['macos' => 'macOS', 'windows' => 'Windows', 'android' => 'Android', 'ios' => 'iOS', 'android_tv' => 'Android TV'];

        return [
            'labels' => array_map(fn ($k) => $names[$k] ?? $k, array_keys($d)),
            'datasets' => [['data' => array_values($d), 'backgroundColor' => ['#E9B44C', '#60A5FA', '#34D399', '#A78BFA', '#F472B6', '#9CA3AF']]],
        ];
    }
}
