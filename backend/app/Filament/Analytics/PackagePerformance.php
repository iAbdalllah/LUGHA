<?php

namespace App\Filament\Analytics;

use Filament\Widgets\Widget;

class PackagePerformance extends Widget
{
    use UsesMetrics;

    protected static bool $isLazy = false;

    protected string $view = 'filament.analytics.packages';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return ['rows' => $this->metrics()->packages()];
    }
}
