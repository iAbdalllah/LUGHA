<?php

namespace App\Filament\Analytics;

use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KpiStats extends StatsOverviewWidget
{
    use UsesMetrics;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $k = $this->metrics()->kpis();
        $sar = fn (int $v) => Money::format($v, 'SAR', 'en');

        return [
            Stat::make(__('Net revenue'), $sar($k['net']))->description(__('Gross :g · refunds :r', ['g' => $sar($k['gross']), 'r' => $sar($k['refunded'])]))->color('warning'),
            Stat::make(__('Paid orders'), (string) $k['orders'])->description(__('Average order :v', ['v' => $sar($k['aov'])])),
            Stat::make(__('Buyers'), (string) $k['buyers'])->description(__('Per buyer :v', ['v' => $sar($k['arppu'])])),
            Stat::make(__('Visitor → buyer'), $k['conversion'].'%')->description(__(':n package-page visitors', ['n' => $k['visitors']])),
            Stat::make(__('Refund rate'), $k['refund_rate'].'%')->color($k['refund_rate'] > 5 ? 'danger' : 'success'),
            Stat::make(__('Repeat buyers'), $k['repeat_rate'].'%')->description(__('Buyers with 2+ orders — input for the subscriptions decision')),
        ];
    }
}
