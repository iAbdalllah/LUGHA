<?php

namespace App\Filament\Resources\ReferenceData;

use App\Filament\Resources\TranslationCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTranslationCategorys extends ManageRecords
{
    protected static string $resource = TranslationCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
