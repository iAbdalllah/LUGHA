<?php

namespace App\Filament\Resources\Support\Pages;

use App\Filament\Resources\Support\SupportTicketResource;
use Filament\Resources\Pages\ListRecords;

class ListSupportTickets extends ListRecords
{
    protected static string $resource = SupportTicketResource::class;
}
