<?php

namespace App\Filament\Resources\Packages;

use App\Enums\PackageStatus;
use App\Enums\UpdatePolicy;
use App\Filament\Support\RestrictedToRoles;
use App\Filament\Support\Translatable;
use App\Models\LocalizationPackage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PackageResource extends Resource
{
    use RestrictedToRoles;

    protected static ?string $model = LocalizationPackage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 2;

    protected static function allowedRoles(): array
    {
        return ['catalog_manager'];
    }

    public static function getModelLabel(): string
    {
        return __('Package');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Packages & pricing');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    /** SAR decimal in the form, halalas in the database. */
    private static function money(string $name, string $label, bool $required = false): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->minValue(0)->step('0.01')->suffix('SAR')->required($required)
            ->formatStateUsing(fn ($state) => $state === null ? null : number_format($state / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : (int) round(((float) $state) * 100));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Package'))->columns(2)->columnSpanFull()->schema([
                Select::make('game_id')->label(__('Game'))->relationship('game', 'slug')->getOptionLabelFromRecordUsing(fn ($record) => $record->title)
                    ->searchable()->preload()->required()->live(),
                TextInput::make('slug')->label(__('Slug (package_id)'))->required()->maxLength(100)->alphaDash()->unique(ignoreRecord: true)
                    ->helperText(__('e.g. sandline-sa-full')),
                Translatable::input('name', __('Name')),
                Translatable::input('summary', __('Summary'), required: false),
                Select::make('style_id')->label(__('Localization style'))->relationship('style', 'slug')->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->required()->preload(),
                TextInput::make('locale')->default('ar-SA')->required()->maxLength(10),
                TextInput::make('tier_rank')->label(__('Tier rank (higher = more content)'))->numeric()->default(1)->required(),
                TextInput::make('sort_order')->label(__('Display order'))->numeric()->default(0),
                Select::make('platforms')->label(__('Platforms'))->relationship('platforms', 'slug')->multiple()->preload()
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                Select::make('status')->label(__('Status'))->options(PackageStatus::options())->default('draft')->required()
                    ->helperText(__('Publishing requires cleared rights on the game.')),
            ]),
            Section::make(__('Content'))->description(__('Exactly which content categories this package delivers. Nothing is hard-coded: build any combination.'))
                ->columnSpanFull()->schema([
                    CheckboxList::make('categories')->label(__('Content categories'))->relationship('categories', 'slug')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->columns(3)->bulkToggleable(),
                    Toggle::make('is_bundle')->label(__('This is a bundle of other packages'))->live(),
                    Select::make('inclusions')->label(__('Included packages'))->multiple()->visible(fn (Get $get) => (bool) $get('is_bundle'))
                        ->relationship('inclusions', 'slug', fn (Builder $query, Get $get, ?LocalizationPackage $record) => $query
                            ->where('localization_packages.game_id', $get('game_id'))->where('localization_packages.is_bundle', false)
                            ->when($record, fn ($q) => $q->where('localization_packages.id', '!=', $record->id))),
                ]),
            Section::make(__('Updates'))->columns(3)->columnSpanFull()->schema([
                Select::make('update_policy')->label(__('Included updates'))->options(UpdatePolicy::options())->default('version_line')->required()->live(),
                TextInput::make('update_months')->label(__('Months of updates'))->numeric()->minValue(1)
                    ->visible(fn (Get $get) => $get('update_policy') === 'duration')->required(fn (Get $get) => $get('update_policy') === 'duration'),
                TextInput::make('current_major')->label(__('Current major version'))->numeric()->minValue(1)->default(1)
                    ->helperText(__('Buyers on "version line" receive every release of this major.')),
            ]),
            Section::make(__('Pricing'))->description(__('Prices include 15% VAT.'))->columnSpanFull()->schema([
                Repeater::make('prices')->relationship()->label(__('Prices'))->columns(5)->defaultItems(1)->schema([
                    Select::make('currency')->options(['SAR' => 'SAR'])->default('SAR')->required(),
                    self::money('base_amount', __('Base price'), true),
                    self::money('sale_amount', __('Sale price')),
                    DateTimePicker::make('sale_starts_at')->label(__('Sale starts')),
                    DateTimePicker::make('sale_ends_at')->label(__('Sale ends')),
                ]),
                Repeater::make('upgradeOffers')->relationship()->label(__('Upgrade prices (owners of another package pay this to get this one)'))
                    ->columns(3)->defaultItems(0)->schema([
                        Select::make('from_package_id')->label(__('Upgrade from'))->required()
                            ->options(fn (Get $get, ?LocalizationPackage $record) => LocalizationPackage::query()
                                ->where('game_id', $get('../../game_id'))->when($record, fn ($q) => $q->whereKeyNot($record->id))
                                ->get()->mapWithKeys(fn ($p) => [$p->id => $p->name.' ('.$p->slug.')'])),
                        Hidden::make('currency')->default('SAR'),
                        self::money('amount', __('Upgrade price'), true),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('game.title')->label(__('Game'))->sortable(),
                TextColumn::make('name')->label(__('Name'))->weight('bold')->description(fn ($record) => $record->slug),
                TextColumn::make('prices.base_amount')->label(__('Price'))->formatStateUsing(fn ($state) => number_format($state / 100, 2).' SAR'),
                TextColumn::make('categories_count')->counts('categories')->label(__('Categories')),
                TextColumn::make('update_policy')->label(__('Updates'))->formatStateUsing(fn ($state) => __('status.update_policy.'.$state->value)),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->color(fn ($state) => $state === PackageStatus::Published ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => __('status.package.'.$state->value)),
            ])
            ->filters([
                SelectFilter::make('game')->label(__('Game'))->relationship('game', 'slug'),
                SelectFilter::make('status')->label(__('Status'))->options(PackageStatus::options()),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('publish')->label(__('Publish'))->icon(Heroicon::OutlinedEye)->color('success')
                    ->visible(fn ($record) => $record->status !== PackageStatus::Published)
                    ->requiresConfirmation()
                    ->action(function (LocalizationPackage $record) {
                        try {
                            $record->update(['status' => PackageStatus::Published]);
                            Notification::make()->title(__('Published'))->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
                Action::make('unpublish')->label(__('Unpublish'))->icon(Heroicon::OutlinedEyeSlash)->color('warning')
                    ->visible(fn ($record) => $record->status === PackageStatus::Published)
                    ->requiresConfirmation()
                    ->modalDescription(__('Owners keep their access; the package just stops being sold.'))
                    ->action(fn (LocalizationPackage $record) => $record->update(['status' => PackageStatus::Unpublished])),
            ])
            ->defaultSort('game_id')
            ->groups(['game.slug']);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }
}
