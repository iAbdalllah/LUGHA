<?php

namespace App\Filament\Resources\Packages\RelationManagers;

use App\Domain\Quality\QaScorer;
use App\Filament\Support\Translatable;
use App\Jobs\BuildPackageVersionJob;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
                TextColumn::make('build_status')->label(__('Build'))->badge()
                    ->color(fn ($state) => match ($state) {
                        'validated', 'released' => 'success', 'failed' => 'danger', 'building' => 'warning', default => 'gray'
                    })
                    ->tooltip(fn ($record) => $record->build_log),
                TextColumn::make('entry_count')->label(__('Entries'))->placeholder('—'),
                TextColumn::make('minGameVersion.version')->label(__('Game from')),
                TextColumn::make('maxGameVersion.version')->label(__('Game to')),
                TextColumn::make('released_at')->label(__('Released'))->dateTime(),
            ])
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data) => [...$data, 'created_by' => auth()->id()])])
            ->recordActions([
                EditAction::make()->mutateRecordDataUsing(fn (array $data, $record) => [...$data, 'release_notes' => $record->getTranslations('release_notes')]),
                Action::make('build')->label(__('Build package'))->icon(Heroicon::OutlinedCog6Tooth)
                    ->visible(fn ($record) => in_array($record->build_status, ['pending', 'failed', 'validated'], true))
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        try {
                            BuildPackageVersionJob::dispatchSync($record);
                        } catch (\Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                            return;
                        }
                        $record->refresh();
                        $report = $record->build_report ?? [];
                        Notification::make()->title(__('Build finished: :status', ['status' => $record->build_status]))
                            ->body(collect([$record->build_log, ...collect($report['errors'] ?? [])->take(8)->map(fn ($i) => '✗ '.($i['entry_id'] ? '#'.$i['entry_id'].' ' : '').$i['message']),
                                ...collect($report['warnings'] ?? [])->take(5)->map(fn ($i) => '⚠ '.($i['entry_id'] ? '#'.$i['entry_id'].' ' : '').$i['message'])])->filter()->implode("\n"))
                            ->{$record->build_status === 'validated' ? 'success' : 'danger'}()->persistent()->send();
                    }),
                Action::make('release')->label(__('Mark released'))->icon(Heroicon::OutlinedRocketLaunch)->requiresConfirmation()
                    ->modalDescription(__('A version can be released only after a successful build.'))
                    ->visible(fn ($record) => $record->build_status === 'validated')
                    ->action(function ($record) {
                        $record->update(['build_status' => 'released', 'released_at' => now()]);
                        app(QaScorer::class)->refresh($record->package);
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
