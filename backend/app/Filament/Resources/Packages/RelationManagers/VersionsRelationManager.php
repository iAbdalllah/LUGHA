<?php

namespace App\Filament\Resources\Packages\RelationManagers;

use App\Filament\Support\Translatable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Release records. In Phase 2 the builder (lugha-pkg) fills entry_count/coverage and uploads the signed file;
 * until then a version documents what will ship and its changelog.
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('Versions & release notes');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('version')->label(__('Version (semver)'))->required()->regex('/^\d+\.\d+\.\d+$/')->placeholder('1.0.0'),
            TextInput::make('min_app_version')->label(__('Minimum app version'))->default('1.0.0')->required(),
            Select::make('game_version_min_id')->label(__('From game version'))
                ->options(fn () => $this->getOwnerRecord()->game->versions()->pluck('version', 'id')),
            Select::make('game_version_max_id')->label(__('To game version'))
                ->options(fn () => $this->getOwnerRecord()->game->versions()->pluck('version', 'id')),
            Translatable::input('release_notes', __('Release notes'), required: false, textarea: true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->label(__('Version'))->fontFamily('mono'),
                TextColumn::make('build_status')->label(__('Build'))->badge(),
                TextColumn::make('minGameVersion.version')->label(__('Game from')),
                TextColumn::make('maxGameVersion.version')->label(__('Game to')),
                TextColumn::make('released_at')->label(__('Released'))->dateTime(),
            ])
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data) => [...$data, 'created_by' => auth()->id()])])
            ->recordActions([
                EditAction::make()->mutateRecordDataUsing(fn (array $data, $record) => [...$data, 'release_notes' => $record->getTranslations('release_notes')]),
                Action::make('release')->label(__('Mark released'))->icon(Heroicon::OutlinedRocketLaunch)->requiresConfirmation()
                    ->visible(fn ($record) => $record->build_status !== 'released')
                    ->action(fn ($record) => $record->update(['build_status' => 'released', 'released_at' => now()])),
            ])
            ->defaultSort('id', 'desc');
    }
}
