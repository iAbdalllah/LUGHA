<?php

namespace App\Filament\Resources\Support;

use App\Filament\Support\RestrictedToRoles;
use App\Models\SupportTicket;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SupportTicketResource extends Resource
{
    use RestrictedToRoles;

    protected static ?string $slug = 'support-tickets';

    protected static ?string $model = SupportTicket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?int $navigationSort = 5;

    protected static function allowedRoles(): array
    {
        return ['support'];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('Support ticket');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Support');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Commerce');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = SupportTicket::where('status', 'open')->count();

        return $n ? (string) $n : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->columnSpanFull()->schema([
                TextEntry::make('subject')->label(__('Subject'))->columnSpan(2),
                TextEntry::make('category')->label(__('Category'))->badge()->formatStateUsing(fn ($state) => __('support.category.'.$state)),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('user.email')->label(__('Customer')),
                TextEntry::make('created_at')->label(__('Opened'))->dateTime(),
            ]),
            Section::make(__('Conversation'))->columnSpanFull()->schema([
                RepeatableEntry::make('messages')->hiddenLabel()->schema([
                    TextEntry::make('author.name')->hiddenLabel()->weight('bold')
                        ->suffix(fn ($record) => ($record->is_internal ? ' · '.__('internal note') : '').' · '.$record->created_at->diffForHumans()),
                    TextEntry::make('body')->hiddenLabel()->prose(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject')->label(__('Subject'))->searchable()->weight('bold'),
                TextColumn::make('user.email')->label(__('Customer'))->searchable(),
                TextColumn::make('category')->label(__('Category'))->badge()->formatStateUsing(fn ($state) => __('support.category.'.$state)),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn ($state) => $state === 'open' ? 'warning' : 'gray'),
                TextColumn::make('updated_at')->label(__('Updated'))->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(['open' => 'open', 'pending_user' => 'pending_user', 'resolved' => 'resolved', 'closed' => 'closed'])->default('open')])
            ->recordActions([ViewAction::make()])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportTickets::route('/'),
            'view' => Pages\ViewSupportTicket::route('/{record}'),
        ];
    }
}
