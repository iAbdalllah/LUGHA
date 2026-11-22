<?php

namespace App\Filament\Resources\Translations\Pages;

use App\Filament\Resources\Translations\TranslationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTranslation extends CreateRecord
{
    protected static string $resource = TranslationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'translator_id' => auth()->id(), 'status' => 'draft'];
    }
}
