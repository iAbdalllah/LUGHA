<?php

namespace App\Filament\Pages;

use App\Filament\Analytics as Widgets;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/** Growth analytics: revenue, funnel, package performance, usage and community health. */
class Analytics extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'analytics';

    protected static ?int $navigationSort = -1;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'finance', 'catalog_manager']) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Analytics');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedChartBar;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Analytics');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('range')->label(__('Period'))->default('30')->selectablePlaceholder(false)->options([
                '7' => __('Last 7 days'), '30' => __('Last 30 days'), '90' => __('Last 90 days'), '365' => __('Last 12 months'),
            ]),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            Widgets\KpiStats::class,
            Widgets\RevenueChart::class,
            Widgets\FunnelChart::class,
            Widgets\DevicesChart::class,
            Widgets\PackagePerformance::class,
            Widgets\CommunityHealth::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
