<?php

namespace App\Filament\Resources\Reviews\Pages;

use App\Filament\Resources\Reviews\PackageReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListPackageReviews extends ListRecords
{
    protected static string $resource = PackageReviewResource::class;
}
