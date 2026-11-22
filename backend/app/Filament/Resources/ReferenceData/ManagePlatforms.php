<?php

namespace App\Filament\Resources\ReferenceData;

use App\Filament\Resources\PlatformResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePlatforms extends ManageRecords
{
    protected static string $resource = PlatformResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
