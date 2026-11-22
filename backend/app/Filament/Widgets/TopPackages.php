<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\LocalizationPackage;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\DB;

class TopPackages extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'finance', 'catalog_manager']) ?? false;
    }

    public function table(Table $table): Table
    {
        $sales = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', [OrderStatus::Paid->value, OrderStatus::PartiallyRefunded->value])
            ->groupBy('order_items.package_id')
            ->select('order_items.package_id', DB::raw('count(*) as sold'), DB::raw('sum(order_items.unit_amount - order_items.refunded_amount) as revenue'));

        return $table
            ->heading(__('Sales per package'))
            ->query(LocalizationPackage::query()->with('game')
                ->joinSub($sales, 's', 's.package_id', '=', 'localization_packages.id')
                ->select('localization_packages.*', 's.sold', 's.revenue')
                ->orderByDesc('s.revenue'))
            ->columns([
                TextColumn::make('game.title')->label(__('Game')),
                TextColumn::make('name')->label(__('Package')),
                TextColumn::make('sold')->label(__('Sold')),
                TextColumn::make('revenue')->label(__('Revenue'))->formatStateUsing(fn ($state) => Money::format((int) $state, 'SAR', 'en')),
            ])
            ->paginated([10]);
    }
}
