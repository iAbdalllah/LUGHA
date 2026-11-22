<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CommerceStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'finance']) ?? false;
    }

    protected function getStats(): array
    {
        $since = now()->subDays(30);
        $paid = Order::whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded])->where('paid_at', '>=', $since);
        $revenue = (int) (clone $paid)->sum('total_amount') - (int) (clone $paid)->sum('refunded_amount');

        $daily = collect(range(13, 0))->map(fn ($d) => (int) Order::whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded])
            ->whereDate('paid_at', now()->subDays($d)->toDateString())->sum('total_amount') / 100)->all();

        return [
            Stat::make(__('Net revenue, 30 days'), Money::format($revenue, 'SAR', 'en'))->chart($daily)->color('warning'),
            Stat::make(__('Paid orders, 30 days'), (string) (clone $paid)->count()),
            Stat::make(__('Active package grants'), (string) Entitlement::where('status', 'active')->count()),
            Stat::make(__('Customers'), (string) User::where('is_staff', false)->count())
                ->description(__(':n new in 30 days', ['n' => User::where('is_staff', false)->where('created_at', '>=', $since)->count()])),
        ];
    }
}
