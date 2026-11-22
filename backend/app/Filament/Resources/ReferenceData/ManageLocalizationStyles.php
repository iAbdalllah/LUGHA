<?php

namespace App\Filament\Resources\ReferenceData;

use App\Filament\Resources\LocalizationStyleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLocalizationStyles extends ManageRecords
{
    protected static string $resource = LocalizationStyleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
