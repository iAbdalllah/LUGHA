<?php

namespace App\Filament\Resources\Translations\Pages;

use App\Domain\Localization\TranslationTransfer;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Translations\TranslationResource;
use App\Jobs\ImportTranslationsJob;
use App\Models\Game;
use App\Models\LocalizationStyle;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListTranslations extends ListRecords
{
    protected static string $resource = TranslationResource::class;

    protected function getHeaderActions(): array
    {
        $games = fn () => Game::all()->mapWithKeys(fn ($g) => [$g->id => $g->title]);

        return [
            Action::make('import')->label(__('Import CSV / JSON / XLSX'))->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->schema([
                    Select::make('game_id')->label(__('Game'))->options($games)->required(),
                    Select::make('style_id')->label(__('Localization style'))->options(fn () => LocalizationStyle::all()->mapWithKeys(fn ($s) => [$s->id => $s->name]))->required(),
                    FileUpload::make('file')->label(__('File'))->disk('local')->directory('imports')->required()
                        ->maxSize(config('lugha.max_upload_kb'))
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/json', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->helperText(__('Columns: :cols', ['cols' => implode(', ', TranslationTransfer::COLUMNS)])),
                ])
                ->action(function (array $data) {
                    ImportTranslationsJob::dispatch($data['file'], Game::findOrFail($data['game_id']), LocalizationStyle::findOrFail($data['style_id']), auth()->user());
                    Notification::make()->title(__('Import started'))
                        ->body(__('Large files take a few minutes. You will get a notification (the bell) when it is done.'))->success()->send();
                }),
            Action::make('export')->label(__('Export'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->schema([
                    Select::make('game_id')->label(__('Game'))->options($games)->required(),
                    Select::make('format')->label(__('Format'))->options(['csv' => 'CSV', 'xlsx' => 'XLSX', 'json' => 'JSON'])->default('xlsx')->required(),
                    Select::make('statuses')->label(__('Statuses (empty = all)'))->options(TranslationStatus::options())->multiple(),
                ])
                ->action(fn (array $data) => response()->download(
                    app(TranslationTransfer::class)->export(Game::findOrFail($data['game_id']), $data['format'], $data['statuses'] ?: null)
                )->deleteFileAfterSend()),
            CreateAction::make(),
        ];
    }
}
