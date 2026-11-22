<?php

namespace App\Filament\Analytics;

use Filament\Widgets\Widget;

class CommunityHealth extends Widget
{
    use UsesMetrics;

    protected static bool $isLazy = false;

    protected string $view = 'filament.analytics.community';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return ['c' => $this->metrics()->community()];
    }
}
