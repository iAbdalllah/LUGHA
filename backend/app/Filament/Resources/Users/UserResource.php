<?php

namespace App\Filament\Resources\Users;

use App\Filament\Support\RestrictedToRoles;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    use RestrictedToRoles;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 4;

    protected static function allowedRoles(): array
    {
        return ['support'];
    }

    public static function canCreate(): bool
    {
        return false; // customers register themselves; staff are promoted from existing accounts
    }

    public static function getModelLabel(): string
    {
        return __('User');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Commerce');
    }

    private static function superAdmin(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(120),
            TextInput::make('email')->label(__('Email'))->email()->required()->unique(ignoreRecord: true),
            Select::make('status')->label(__('Account status'))->options(['active' => __('Active'), 'suspended' => __('Suspended'), 'closed' => __('Closed')])->required(),
            Select::make('locale')->label(__('Language'))->options(['ar' => 'العربية', 'en' => 'English'])->required(),
            Toggle::make('is_staff')->label(__('Staff (can sign in to admin)'))->visible(fn () => self::superAdmin()),
            Select::make('roles')->label(__('Roles'))->relationship('roles', 'name')->multiple()->preload()->visible(fn () => self::superAdmin()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('email')->label(__('Email'))->searchable()->copyable(),
                TextColumn::make('active_entitlements_count')->counts('activeEntitlements')->label(__('Packages owned')),
                TextColumn::make('orders_count')->counts('orders')->label(__('Orders')),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn ($state) => $state === 'active' ? 'success' : 'danger'),
                IconColumn::make('email_verified_at')->label(__('Verified'))->boolean()->getStateUsing(fn ($record) => (bool) $record->email_verified_at),
                IconColumn::make('is_staff')->label(__('Staff'))->boolean(),
                TextColumn::make('contributor_status')->label(__('Contributor'))->badge()->placeholder('—')
                    ->formatStateUsing(fn ($state) => __('community.contributor_status.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'applied' => 'warning', default => 'gray'
                    }),
                TextColumn::make('created_at')->label(__('Joined'))->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => __('Active'), 'suspended' => __('Suspended'), 'closed' => __('Closed')]),
                TernaryFilter::make('is_staff')->label(__('Staff')),
                SelectFilter::make('contributor_status')->label(__('Contributor'))
                    ->options(['applied' => __('community.contributor_status.applied'), 'approved' => __('community.contributor_status.approved'), 'suspended' => __('community.contributor_status.suspended')]),
            ])
            ->recordActions([
                Action::make('approve_contributor')->label(__('Approve contributor'))->icon(Heroicon::OutlinedUserPlus)->color('success')
                    ->visible(fn ($record) => self::canManageContributors() && in_array($record->contributor_status, ['applied', 'suspended'], true))
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->forceFill(['contributor_status' => 'approved'])->save()),
                Action::make('suspend_contributor')->label(__('Suspend contributor'))->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn ($record) => self::canManageContributors() && $record->contributor_status === 'approved')
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->forceFill(['contributor_status' => 'suspended'])->save()),
                EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** Contributor applications are handled by the same staff who manage customer accounts. */
    public static function canManageContributors(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'support']) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false; // close accounts instead; orders must keep their customer
    }

    public static function getRelations(): array
    {
        return [RelationManagers\EntitlementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
