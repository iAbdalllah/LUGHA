<?php

namespace App\Filament\Resources\Games\Pages;

use App\Filament\Resources\Games\GameResource;
use App\Filament\Support\FillsTranslations;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGame extends EditRecord
{
    use FillsTranslations;

    protected static string $resource = GameResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
