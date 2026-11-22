<?php

namespace App\Filament\Analytics;

use App\Domain\Analytics\Metrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

trait UsesMetrics
{
    use InteractsWithPageFilters;

    protected function metrics(): Metrics
    {
        return Metrics::forFilter($this->pageFilters['range'] ?? '30');
    }
}
