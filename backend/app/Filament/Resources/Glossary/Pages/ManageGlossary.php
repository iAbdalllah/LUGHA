<?php

namespace App\Filament\Resources\Glossary\Pages;

use App\Filament\Resources\Glossary\GlossaryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGlossary extends ManageRecords
{
    protected static string $resource = GlossaryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
