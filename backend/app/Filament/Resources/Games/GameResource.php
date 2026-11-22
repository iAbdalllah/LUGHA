<?php

namespace App\Filament\Resources\Games;

use App\Enums\GameStatus;
use App\Enums\RightsStatus;
use App\Filament\Support\RestrictedToRoles;
use App\Filament\Support\Translatable;
use App\Models\Game;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GameResource extends Resource
{
    use RestrictedToRoles;

    protected static ?string $model = Game::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    protected static function allowedRoles(): array
    {
        return ['catalog_manager'];
    }

    public static function getModelLabel(): string
    {
        return __('Game');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Games');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Game'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('slug')->label(__('Slug (package game_id)'))->required()->maxLength(80)->alphaDash()->unique(ignoreRecord: true),
                Select::make('platforms')->label(__('Platforms'))->relationship('platforms', 'slug')->multiple()->preload()
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name),
                Translatable::input('title', __('Title')),
                Translatable::input('description', __('Description'), required: false, textarea: true),
                TextInput::make('developer')->label(__('Developer'))->maxLength(160),
                TextInput::make('publisher')->label(__('Publisher'))->maxLength(160),
                DatePicker::make('release_date')->label(__('Release date')),
                Select::make('status')->label(__('Status'))->options(GameStatus::options())->required()->default('draft'),
                Toggle::make('is_featured')->label(__('Featured on home page')),
            ]),
            Section::make(__('Rights'))->description(__('Packages can only be published for games whose rights are licensed or permitted.'))
                ->columns(2)->columnSpanFull()->schema([
                    Select::make('rights_status')->label(__('Rights status'))->options(RightsStatus::options())->required()->default('pending'),
                    Textarea::make('rights_notes')->label(__('Rights notes (agreement, contact, scope)'))->rows(3),
                ]),
            Section::make(__('Media'))->columns(2)->columnSpanFull()->schema([
                FileUpload::make('cover_path')->label(__('Cover (3:4)'))->image()->disk('public')->directory('games/covers')->imageAspectRatio('3:4'),
                FileUpload::make('hero_path')->label(__('Hero (16:9)'))->image()->disk('public')->directory('games/heroes'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('cover_path')->label('')->disk('public')->height(48),
                TextColumn::make('title')->label(__('Title'))->searchable(query: fn ($q, $search) => $q->where(fn ($w) => $w->where('title->ar', 'like', "%{$search}%")->orWhere('title->en', 'like', "%{$search}%")))->weight('bold'),
                TextColumn::make('slug')->fontFamily('mono')->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => __('status.game.'.$state->value)),
                TextColumn::make('rights_status')->label(__('Rights'))->badge()
                    ->color(fn ($state) => $state->allowsSelling() ? 'success' : ($state === RightsStatus::Rejected ? 'danger' : 'warning'))
                    ->formatStateUsing(fn ($state) => __('status.rights.'.$state->value)),
                TextColumn::make('packages_count')->counts('packages')->label(__('Packages')),
                IconColumn::make('is_featured')->label(__('Featured'))->boolean(),
                TextColumn::make('updated_at')->label(__('Updated'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(GameStatus::options()),
                SelectFilter::make('rights_status')->label(__('Rights'))->options(RightsStatus::options()),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [RelationManagers\VersionsRelationManager::class, RelationManagers\MediaRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGames::route('/'),
            'create' => Pages\CreateGame::route('/create'),
            'edit' => Pages\EditGame::route('/{record}/edit'),
        ];
    }
}
