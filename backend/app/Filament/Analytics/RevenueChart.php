<?php

namespace App\Filament\Analytics;

use Filament\Widgets\ChartWidget;

class RevenueChart extends ChartWidget
{
    use UsesMetrics;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    public function getHeading(): string
    {
        return __('Revenue per day (SAR)');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $s = $this->metrics()->revenueSeries();

        return [
            'labels' => $s['labels'],
            'datasets' => [
                ['label' => __('Gross'), 'data' => $s['gross'], 'borderColor' => '#E9B44C', 'backgroundColor' => 'rgba(233,180,76,.15)', 'fill' => true, 'tension' => .3],
                ['label' => __('Refunds'), 'data' => $s['refunds'], 'borderColor' => '#EF6B6B', 'backgroundColor' => 'transparent', 'tension' => .3],
            ],
        ];
    }
}
