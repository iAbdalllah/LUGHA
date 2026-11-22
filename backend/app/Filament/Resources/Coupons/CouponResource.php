<?php

namespace App\Filament\Resources\Coupons;

use App\Filament\Support\RestrictedToRoles;
use App\Models\Coupon;
use App\Models\Game;
use App\Models\LocalizationPackage;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CouponResource extends Resource
{
    use RestrictedToRoles;

    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 3;

    protected static function allowedRoles(): array
    {
        return ['finance', 'catalog_manager'];
    }

    public static function getModelLabel(): string
    {
        return __('Coupon');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Coupons');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Commerce');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->label(__('Code'))->required()->maxLength(40)->alphaDash()->unique(ignoreRecord: true)->extraInputAttributes(['dir' => 'ltr']),
            Select::make('kind')->label(__('Type'))->options(['percent' => __('Percent'), 'fixed' => __('Fixed amount (SAR)')])->required()->live()->default('percent'),
            TextInput::make('value')->label(__('Value'))->required()->numeric()->minValue(1)
                ->maxValue(fn (Get $get) => $get('kind') === 'percent' ? 100 : null)
                ->suffix(fn (Get $get) => $get('kind') === 'percent' ? '%' : 'SAR')
                ->formatStateUsing(fn ($state, $record) => $record?->kind === 'fixed' && $state ? $state / 100 : $state)
                ->dehydrateStateUsing(fn ($state, Get $get) => $get('kind') === 'fixed' ? (int) round($state * 100) : (int) $state),
            TextInput::make('min_order_amount')->label(__('Minimum order (SAR)'))->numeric()
                ->formatStateUsing(fn ($state) => $state ? $state / 100 : null)->dehydrateStateUsing(fn ($state) => $state ? (int) round($state * 100) : null),
            TextInput::make('max_redemptions')->label(__('Total uses (empty = unlimited)'))->numeric()->minValue(1),
            TextInput::make('per_user_limit')->label(__('Uses per customer'))->numeric()->minValue(1)->default(1)->required(),
            Select::make('game_ids')->label(__('Only these games'))->multiple()->options(fn () => Game::all()->mapWithKeys(fn ($g) => [$g->id => $g->title])),
            Select::make('package_ids')->label(__('Only these packages'))->multiple()->options(fn () => LocalizationPackage::with('game')->get()->mapWithKeys(fn ($p) => [$p->id => $p->game->title.' — '.$p->name])),
            DateTimePicker::make('starts_at')->label(__('Starts')),
            DateTimePicker::make('ends_at')->label(__('Ends')),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label(__('Code'))->fontFamily('mono')->searchable()->copyable(),
                TextColumn::make('value')->label(__('Value'))->formatStateUsing(fn ($state, $record) => $record->kind === 'percent' ? $state.'%' : number_format($state / 100, 2).' SAR'),
                TextColumn::make('redeemed_count')->label(__('Used'))->formatStateUsing(fn ($state, $record) => $state.($record->max_redemptions ? ' / '.$record->max_redemptions : '')),
                TextColumn::make('ends_at')->label(__('Ends'))->dateTime()->placeholder('—'),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCoupons::route('/')];
    }
}
