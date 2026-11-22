<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Support\FillsTranslations;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    use FillsTranslations;

    protected static string $resource = PackageResource::class;
}
