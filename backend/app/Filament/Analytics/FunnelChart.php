<?php

namespace App\Filament\Analytics;

use Filament\Widgets\ChartWidget;

class FunnelChart extends ChartWidget
{
    use UsesMetrics;

    protected static bool $isLazy = false;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return __('Funnel (unique people)');
    }

    public function getDescription(): ?string
    {
        return __('Views and cart adds are cookie-less daily visitors; checkouts and purchases are signed-in customers.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]]];
    }

    protected function getData(): array
    {
        $f = $this->metrics()->funnel();

        return [
            'labels' => [__('Package page'), __('Added to cart'), __('Started checkout'), __('Purchased')],
            'datasets' => [['data' => array_values($f), 'backgroundColor' => ['#6B7280', '#9CA3AF', '#D4A04A', '#E9B44C'], 'borderRadius' => 6]],
        ];
    }
}
