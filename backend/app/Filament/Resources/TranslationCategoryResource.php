<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReferenceData\ManageTranslationCategorys;
use App\Filament\Support\RestrictedToRoles;
use App\Filament\Support\Translatable;
use App\Models\TranslationCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TranslationCategoryResource extends Resource
{
    use RestrictedToRoles;

    protected static ?string $model = TranslationCategory::class;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static function allowedRoles(): array
    {
        return ['catalog_manager'];
    }

    public static function getModelLabel(): string
    {
        return __('Content category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Content categories');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')->required()->maxLength(40)->alphaDash()->unique(ignoreRecord: true),
            Translatable::input('name', __('Name')),
            TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slug')->searchable()->fontFamily('mono'),
                TextColumn::make('name')->label(__('Name'))->searchable(),
            ])
            ->recordActions([
                EditAction::make()->mutateRecordDataUsing(fn (array $data, $record) => [...$data, 'name' => $record->getTranslations('name')]),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTranslationCategorys::route('/')];
    }
}
