<?php

namespace App\Filament\Resources\Translations;

use App\Domain\Localization\TranslationWorkflow;
use App\Enums\TranslationStatus;
use App\Filament\Support\RestrictedToRoles;
use App\Models\GameVersion;
use App\Models\Translation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class TranslationResource extends Resource
{
    use RestrictedToRoles;

    protected static ?string $model = Translation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static ?int $navigationSort = 1;

    protected static function allowedRoles(): array
    {
        return ['translator', 'reviewer', 'catalog_manager'];
    }

    public static function getModelLabel(): string
    {
        return __('Translation line');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Translations');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Translation::where('status', TranslationStatus::InReview)->count();

        return $n ? (string) $n : null;
    }

    public static function isReviewer(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'reviewer']) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Line'))->columns(3)->columnSpanFull()->schema([
                Select::make('game_id')->label(__('Game'))->relationship('game', 'slug')->getOptionLabelFromRecordUsing(fn ($record) => $record->title)
                    ->required()->searchable()->preload()->live(),
                Select::make('category_id')->label(__('Category'))->relationship('category', 'slug')->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->required()->preload(),
                Select::make('style_id')->label(__('Localization style'))->relationship('style', 'slug')->getOptionLabelFromRecordUsing(fn ($record) => $record->name)->required()->preload(),
                TextInput::make('string_key')->label(__('String key'))->maxLength(160)->extraInputAttributes(['dir' => 'ltr']),
                TextInput::make('speaker')->label(__('Speaker / character'))->maxLength(120),
                TextInput::make('locale')->default('ar-SA')->required()->maxLength(10),
            ]),
            Section::make(__('Text'))->columnSpanFull()->schema([
                Textarea::make('source_text')->label(__('Original source text'))->required()->rows(3)->extraInputAttributes(['dir' => 'ltr', 'lang' => 'en']),
                Textarea::make('target_text')->label(__('Arabic translation'))->rows(3)->extraInputAttributes(['dir' => 'rtl', 'lang' => 'ar', 'style' => 'font-size:1.1rem']),
                Textarea::make('context')->label(__('Context for translators'))->rows(2),
            ]),
            Section::make(__('Placement'))->columns(4)->columnSpanFull()->collapsed()->schema([
                TextInput::make('scene_ref')->label(__('Scene'))->maxLength(120),
                TextInput::make('sequence_no')->label(__('Order in scene'))->numeric(),
                Select::make('min_game_version_id')->label(__('From game version'))
                    ->options(fn (Get $get) => GameVersion::where('game_id', $get('game_id'))->pluck('version', 'id')),
                Select::make('max_game_version_id')->label(__('To game version'))
                    ->options(fn (Get $get) => GameVersion::where('game_id', $get('game_id'))->pluck('version', 'id')),
            ]),
        ]);
    }

    private static function transitionAction(string $name, TranslationStatus $to, string $label, string $color, bool $reviewerOnly, bool $askComment = false): Action
    {
        return Action::make($name)->label($label)->color($color)->iconButton()->tooltip($label)
            ->icon(match ($to) {
                TranslationStatus::InReview => Heroicon::OutlinedPaperAirplane,
                TranslationStatus::Approved => Heroicon::OutlinedCheckBadge,
                TranslationStatus::Rejected => Heroicon::OutlinedXCircle,
                TranslationStatus::Published => Heroicon::OutlinedGlobeAlt,
                default => Heroicon::OutlinedArrowUturnLeft,
            })
            ->visible(fn (Translation $record) => $record->status->canMoveTo($to) && (! $reviewerOnly || self::isReviewer()))
            ->schema($askComment ? [Textarea::make('comment')->label(__('Reason'))->required()] : [])
            ->action(function (Translation $record, array $data) use ($to) {
                try {
                    app(TranslationWorkflow::class)->transition($record, $to, auth()->user(), $data['comment'] ?? null);
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('string_key')->label(__('Key'))->fontFamily('mono')->size('xs')->searchable()->toggleable(),
                TextColumn::make('source_text')->label(__('Source'))->searchable()->wrap()->limit(90)->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('target_text')->label(__('Translation'))->searchable()->wrap()->limit(90)->placeholder('—')
                    ->extraAttributes(['dir' => 'rtl', 'lang' => 'ar']),
                TextColumn::make('category.name')->label(__('Category'))->toggleable(),
                TextColumn::make('speaker')->label(__('Speaker'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => $state->label())
                    ->color(fn ($state) => match ($state) {
                        TranslationStatus::Draft => 'gray', TranslationStatus::InReview => 'info', TranslationStatus::Approved => 'success',
                        TranslationStatus::Published => 'warning', TranslationStatus::Rejected => 'danger',
                    }),
                TextColumn::make('revision')->label(__('Rev.'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label(__('Updated'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('game')->label(__('Game'))->relationship('game', 'slug'),
                SelectFilter::make('status')->label(__('Status'))->options(TranslationStatus::options())->multiple(),
                SelectFilter::make('category')->label(__('Category'))->relationship('category', 'slug'),
            ])
            ->recordActions([
                self::transitionAction('submit', TranslationStatus::InReview, __('Send to review'), 'info', false),
                self::transitionAction('approve', TranslationStatus::Approved, __('Approve'), 'success', true),
                self::transitionAction('reject', TranslationStatus::Rejected, __('Reject'), 'danger', true, askComment: true),
                self::transitionAction('publish', TranslationStatus::Published, __('Publish'), 'warning', true),
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_submit')->label(__('Send to review'))->icon(Heroicon::OutlinedPaperAirplane)
                        ->action(fn (Collection $records) => self::bulk($records, TranslationStatus::InReview)),
                    BulkAction::make('bulk_approve')->label(__('Approve'))->icon(Heroicon::OutlinedCheckBadge)->visible(fn () => self::isReviewer())
                        ->action(fn (Collection $records) => self::bulk($records, TranslationStatus::Approved)),
                    BulkAction::make('bulk_publish')->label(__('Publish approved'))->icon(Heroicon::OutlinedGlobeAlt)->visible(fn () => self::isReviewer())
                        ->requiresConfirmation()->action(fn (Collection $records) => self::bulk($records, TranslationStatus::Published)),
                ]),
            ])
            ->defaultSort('updated_at', 'desc')
            ->striped();
    }

    private static function bulk(Collection $records, TranslationStatus $to): void
    {
        $done = 0;
        foreach ($records as $t) {
            if ($t->status->canMoveTo($to) && trim((string) $t->target_text) !== '') {
                app(TranslationWorkflow::class)->transition($t, $to, auth()->user());
                $done++;
            }
        }
        Notification::make()->title(__(':done of :total lines updated', ['done' => $done, 'total' => $records->count()]))->success()->send();
    }

    public static function getRelations(): array
    {
        return [RelationManagers\RevisionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTranslations::route('/'),
            'create' => Pages\CreateTranslation::route('/create'),
            'edit' => Pages\EditTranslation::route('/{record}/edit'),
        ];
    }
}
