<?php

namespace App\Filament\Resources\Community;

use App\Domain\Community\FeedbackService;
use App\Filament\Support\RestrictedToRoles;
use App\Models\TranslationFeedback;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Community queue: player reports and contributor suggestions, resolved by reviewers. */
class TranslationFeedbackResource extends Resource
{
    use RestrictedToRoles;

    protected static ?string $slug = 'community-feedback';

    protected static ?string $model = TranslationFeedback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 3;

    protected static function allowedRoles(): array
    {
        return ['reviewer', 'translator'];
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
        return __('Community feedback');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Community feedback');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = TranslationFeedback::where('status', 'open')->count();

        return $n ? (string) $n : null;
    }

    private static function isReviewer(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'reviewer']) ?? false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->columnSpanFull()->schema([
                TextEntry::make('kind')->label(__('Type'))->badge()->formatStateUsing(fn ($state) => __('community.kind.'.$state)),
                TextEntry::make('reason')->label(__('Reason'))->formatStateUsing(fn ($state) => __('community.reason.'.$state)),
                TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => __('community.status.'.$state)),
                TextEntry::make('source')->label(__('From'))->badge(),
                TextEntry::make('user.email')->label(__('Player')),
                TextEntry::make('package.name')->label(__('Package')),
                TextEntry::make('packageVersion.version')->label(__('Version'))->placeholder('—'),
                TextEntry::make('created_at')->label(__('Received'))->since(),
            ]),
            Section::make(__('Line'))->columnSpanFull()->schema([
                TextEntry::make('translation.source_text')->label(__('Source'))->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('translation.target_text')->label(__('Current translation'))->extraAttributes(['dir' => 'rtl']),
                TextEntry::make('translation.status')->label(__('Line status'))->badge()->formatStateUsing(fn ($state) => $state->label()),
                TextEntry::make('suggested_text')->label(__('Suggested'))->placeholder('—')->extraAttributes(['dir' => 'rtl'])->weight('bold'),
                TextEntry::make('note')->label(__('Player note'))->placeholder('—'),
            ]),
            Section::make(__('Resolution'))->columns(2)->columnSpanFull()->visible(fn ($record) => ! $record->isOpen())->schema([
                TextEntry::make('resolver.name')->label(__('Resolved by')),
                TextEntry::make('resolved_at')->label(__('Resolved'))->dateTime(),
                TextEntry::make('applied_text')->label(__('Applied wording'))->placeholder('—')->extraAttributes(['dir' => 'rtl']),
                TextEntry::make('resolution_note')->label(__('Note'))->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->label(__('Type'))->badge()->formatStateUsing(fn ($state) => __('community.kind.'.$state))
                    ->color(fn ($state) => $state === 'suggestion' ? 'info' : 'warning'),
                TextColumn::make('reason')->label(__('Reason'))->formatStateUsing(fn ($state) => __('community.reason.'.$state)),
                TextColumn::make('translation.source_text')->label(__('Source'))->wrap()->limit(70)->extraAttributes(['dir' => 'ltr'])->searchable(),
                TextColumn::make('translation.target_text')->label(__('Current'))->wrap()->limit(70)->extraAttributes(['dir' => 'rtl']),
                TextColumn::make('suggested_text')->label(__('Suggested'))->wrap()->limit(70)->placeholder('—')->extraAttributes(['dir' => 'rtl']),
                TextColumn::make('package.name')->label(__('Package'))->toggleable(),
                TextColumn::make('user.email')->label(__('Player'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => __('community.status.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'open' => 'warning', 'accepted' => 'success', 'rejected' => 'danger', default => 'gray'
                    }),
                TextColumn::make('created_at')->label(__('Received'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(collect(['open', 'accepted', 'rejected', 'duplicate'])->mapWithKeys(fn ($s) => [$s => __('community.status.'.$s)]))->default('open'),
                SelectFilter::make('kind')->label(__('Type'))->options(['report' => __('community.kind.report'), 'suggestion' => __('community.kind.suggestion')]),
                SelectFilter::make('package')->label(__('Package'))->relationship('package', 'slug'),
            ])
            ->recordActions([
                Action::make('accept')->label(__('Accept'))->icon(Heroicon::OutlinedCheckBadge)->color('success')->iconButton()->tooltip(__('Accept'))
                    ->visible(fn (TranslationFeedback $record) => $record->isOpen() && self::isReviewer())
                    ->fillForm(fn (TranslationFeedback $record) => ['text' => $record->suggested_text ?? $record->translation->target_text])
                    ->schema([
                        Textarea::make('text')->label(__('Wording to apply (leave as is to only confirm the problem)'))->rows(3)->extraInputAttributes(['dir' => 'rtl']),
                        Textarea::make('note')->label(__('Note to the player (optional)'))->rows(2),
                    ])
                    ->modalDescription(__('Changed wording moves the line to Approved; publish it with the next release as usual.'))
                    ->action(fn (TranslationFeedback $record, array $data) => self::run(fn () => app(FeedbackService::class)->accept($record, auth()->user(), $data['text'] ?? null, $data['note'] ?? null))),
                Action::make('reject')->label(__('Reject'))->icon(Heroicon::OutlinedXCircle)->color('danger')->iconButton()->tooltip(__('Reject'))
                    ->visible(fn (TranslationFeedback $record) => $record->isOpen() && self::isReviewer())
                    ->schema([Textarea::make('note')->label(__('Note to the player (optional)'))->rows(2)])
                    ->action(fn (TranslationFeedback $record, array $data) => self::run(fn () => app(FeedbackService::class)->reject($record, auth()->user(), $data['note'] ?? null))),
                Action::make('duplicate')->label(__('Duplicate'))->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')->iconButton()->tooltip(__('Duplicate'))
                    ->visible(fn (TranslationFeedback $record) => $record->isOpen() && self::isReviewer())
                    ->requiresConfirmation()
                    ->action(fn (TranslationFeedback $record) => self::run(fn () => app(FeedbackService::class)->markDuplicate($record, auth()->user()))),
                ViewAction::make()->iconButton(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function run(callable $fn): void
    {
        try {
            $fn();
            Notification::make()->title(__('Saved'))->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTranslationFeedback::route('/'),
            'view' => Pages\ViewTranslationFeedback::route('/{record}'),
        ];
    }
}
