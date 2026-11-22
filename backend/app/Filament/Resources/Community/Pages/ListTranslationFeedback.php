<?php

namespace App\Filament\Resources\Community\Pages;

use App\Filament\Resources\Community\TranslationFeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListTranslationFeedback extends ListRecords
{
    protected static string $resource = TranslationFeedbackResource::class;
}
