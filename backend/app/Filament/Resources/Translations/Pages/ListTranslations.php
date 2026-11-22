<?php

namespace App\Filament\Resources\Translations\Pages;

use App\Domain\Localization\TranslationTransfer;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Translations\TranslationResource;
use App\Models\Game;
use App\Models\LocalizationStyle;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

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
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/json', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->helperText(__('Columns: :cols', ['cols' => implode(', ', TranslationTransfer::COLUMNS)])),
                ])
                ->action(function (array $data) {
                    $path = Storage::disk('local')->path($data['file']);
                    $format = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $format = $format === 'txt' ? 'csv' : $format;
                    try {
                        $stats = app(TranslationTransfer::class)->import($path, $format, Game::findOrFail($data['game_id']), LocalizationStyle::findOrFail($data['style_id']));
                    } catch (\Throwable $e) {
                        Notification::make()->title(__('Import failed'))->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }
                    $body = __(':c created, :u updated, :n unchanged', ['c' => $stats['created'], 'u' => $stats['updated'], 'n' => $stats['unchanged']]);
                    if ($stats['errors']) {
                        $body .= "\n".collect($stats['errors'])->take(10)->map(fn ($m, $line) => __('Line :l: :m', ['l' => $line, 'm' => $m]))->implode("\n");
                    }
                    Notification::make()->title(__('Import finished'))->body($body)->{$stats['errors'] ? 'warning' : 'success'}()->persistent()->send();
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
