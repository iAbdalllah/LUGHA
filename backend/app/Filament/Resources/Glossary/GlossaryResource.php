<?php

namespace App\Filament\Resources\Glossary;

use App\Filament\Support\RestrictedToRoles;
use App\Models\TranslationGlossary;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GlossaryResource extends Resource
{
    use RestrictedToRoles;

    protected static ?string $slug = 'glossary';

    protected static ?string $model = TranslationGlossary::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 2;

    protected static function allowedRoles(): array
    {
        return ['translator', 'reviewer', 'catalog_manager'];
    }

    public static function getModelLabel(): string
    {
        return __('Glossary term');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Glossary');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('game_id')->label(__('Game (empty = all games)'))->relationship('game', 'slug')->getOptionLabelFromRecordUsing(fn ($record) => $record->title),
            TextInput::make('locale')->default('ar-SA')->required(),
            TextInput::make('term')->label(__('Term'))->required()->maxLength(200)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('translation')->label(__('Translation'))->maxLength(200)->extraInputAttributes(['dir' => 'rtl']),
            Toggle::make('do_not_translate')->label(__('Do not translate')),
            Textarea::make('notes')->label(__('Notes'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('term')->label(__('Term'))->searchable()->weight('bold'),
                TextColumn::make('translation')->label(__('Translation'))->searchable(),
                IconColumn::make('do_not_translate')->label(__('Do not translate'))->boolean(),
                TextColumn::make('game.title')->label(__('Game'))->placeholder(__('All games')),
            ])
            ->filters([SelectFilter::make('game')->relationship('game', 'slug')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageGlossary::route('/')];
    }
}
