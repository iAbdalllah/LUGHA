<?php

namespace App\Filament\Resources\Reviews;

use App\Domain\Reviews\ReviewService;
use App\Filament\Support\RestrictedToRoles;
use App\Models\PackageReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Moderation of public package reviews: staff can hide (with a reason shown to the author) or restore, never edit. */
class PackageReviewResource extends Resource
{
    use RestrictedToRoles;

    protected static ?string $slug = 'package-reviews';

    protected static ?string $model = PackageReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?int $navigationSort = 6;

    protected static function allowedRoles(): array
    {
        return ['support', 'catalog_manager'];
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
        return __('Review');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Package reviews');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rating')->label(__('Rating'))->formatStateUsing(fn ($state) => str_repeat('★', (int) $state))->color('warning')->sortable(),
                TextColumn::make('title')->label(__('Title'))->placeholder('—')->searchable(),
                TextColumn::make('body')->label(__('Review'))->wrap()->limit(120)->placeholder('—')->searchable()->extraAttributes(['dir' => 'auto']),
                TextColumn::make('package.name')->label(__('Package')),
                TextColumn::make('user.email')->label(__('Player'))->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn ($state) => $state === 'published' ? 'success' : 'danger'),
                TextColumn::make('hidden_reason')->label(__('Hidden because'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Written'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(['published' => 'published', 'hidden' => 'hidden']),
                SelectFilter::make('rating')->label(__('Rating'))->options([1 => '1★', 2 => '2★', 3 => '3★', 4 => '4★', 5 => '5★']),
                SelectFilter::make('package')->label(__('Package'))->relationship('package', 'slug'),
            ])
            ->recordActions([
                Action::make('hide')->label(__('Hide'))->icon(Heroicon::OutlinedEyeSlash)->color('danger')
                    ->visible(fn (PackageReview $record) => $record->status === 'published')
                    ->schema([TextInput::make('reason')->label(__('Reason (shown to the author)'))->required()->maxLength(200)])
                    ->action(fn (PackageReview $record, array $data) => app(ReviewService::class)->hide($record, auth()->user(), $data['reason'])),
                Action::make('restore')->label(__('Restore'))->icon(Heroicon::OutlinedEye)->color('success')
                    ->visible(fn (PackageReview $record) => $record->status === 'hidden')
                    ->requiresConfirmation()
                    ->action(fn (PackageReview $record) => app(ReviewService::class)->restore($record, auth()->user())),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPackageReviews::route('/')];
    }
}
