<?php

namespace App\Filament\Resources\Support\Pages;

use App\Filament\Resources\Support\SupportTicketResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')->label(__('Reply'))->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->schema([
                    Textarea::make('body')->label(__('Message'))->required()->rows(5),
                    Toggle::make('is_internal')->label(__('Internal note (customer will not see it)')),
                    Select::make('status')->label(__('Set status'))->options(['open' => 'open', 'pending_user' => 'pending_user', 'resolved' => 'resolved', 'closed' => 'closed'])->default('pending_user'),
                ])
                ->action(function (array $data) {
                    $ticket = $this->getRecord();
                    $ticket->messages()->create(['author_id' => auth()->id(), 'body' => $data['body'], 'is_internal' => $data['is_internal'] ?? false]);
                    $ticket->update(['status' => $data['status'], 'assignee_id' => $ticket->assignee_id ?? auth()->id()]);
                    $this->refreshFormData([]);
                }),
        ];
    }
}
