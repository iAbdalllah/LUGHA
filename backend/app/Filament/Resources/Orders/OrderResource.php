<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Support\RestrictedToRoles;
use App\Models\Order;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class OrderResource extends Resource
{
    use RestrictedToRoles;

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 1;

    protected static function allowedRoles(): array
    {
        return ['finance', 'support'];
    }

    public static function canCreate(): bool
    {
        return false; // orders only come from checkout
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('Order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Orders');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Commerce');
    }

    public static function statusColor(OrderStatus $s): string
    {
        return match ($s) {
            OrderStatus::Paid => 'success', OrderStatus::AwaitingPayment, OrderStatus::Pending => 'warning',
            OrderStatus::Failed, OrderStatus::Cancelled => 'danger', default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state, $record) => Money::format((int) $state, $record->currency ?? 'SAR', 'en');

        return $schema->components([
            Section::make(__('Order'))->columns(4)->columnSpanFull()->schema([
                TextEntry::make('number')->label(__('Number'))->placeholder(__('unpaid')),
                TextEntry::make('status')->label(__('Status'))->badge()->color(fn ($state) => self::statusColor($state))->formatStateUsing(fn ($state) => $state->label()),
                TextEntry::make('user.email')->label(__('Customer')),
                TextEntry::make('paid_at')->label(__('Paid at'))->dateTime()->placeholder('—'),
                TextEntry::make('subtotal_amount')->label(__('Subtotal'))->formatStateUsing($money),
                TextEntry::make('discount_amount')->label(__('Discount'))->formatStateUsing($money),
                TextEntry::make('vat_amount')->label(__('VAT included'))->formatStateUsing($money),
                TextEntry::make('total_amount')->label(__('Total'))->formatStateUsing($money)->weight('bold'),
                TextEntry::make('refunded_amount')->label(__('Refunded'))->formatStateUsing($money),
                TextEntry::make('coupon.code')->label(__('Coupon'))->placeholder('—'),
                TextEntry::make('invoice_number')->label(__('Invoice'))->placeholder('—'),
            ]),
            Section::make(__('Items'))->columnSpanFull()->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('package.name')->label(__('Package')),
                    TextEntry::make('kind')->label(__('Type'))->badge(),
                    TextEntry::make('list_amount')->label(__('List price'))->formatStateUsing(fn ($state) => Money::format((int) $state, 'SAR', 'en')),
                    TextEntry::make('unit_amount')->label(__('Charged'))->formatStateUsing(fn ($state) => Money::format((int) $state, 'SAR', 'en')),
                    TextEntry::make('refunded_amount')->label(__('Refunded'))->formatStateUsing(fn ($state) => Money::format((int) $state, 'SAR', 'en')),
                ]),
            ]),
            Section::make(__('Payments'))->columnSpanFull()->schema([
                RepeatableEntry::make('payments')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('gateway')->label(__('Gateway')),
                    TextEntry::make('gateway_ref')->label(__('Reference'))->copyable(),
                    TextEntry::make('method')->label(__('Method'))->placeholder('—'),
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('verified_at')->label(__('Verified'))->dateTime()->placeholder('—'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->placeholder('—')->fontFamily('mono'),
                TextColumn::make('user.email')->label(__('Customer'))->searchable(),
                TextColumn::make('items_count')->counts('items')->label(__('Items')),
                TextColumn::make('total_amount')->label(__('Total'))->formatStateUsing(fn ($state, $record) => Money::format($state, $record->currency, 'en'))->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn ($state) => self::statusColor($state))->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('created_at')->label(__('Created'))->dateTime()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label(__('Status'))->options(OrderStatus::options())])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
