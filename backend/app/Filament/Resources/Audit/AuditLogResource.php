<?php

namespace App\Filament\Resources\Audit;

use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $slug = 'audit-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('Audit entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Audit log');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(AuditLog::query()->with('actor'))
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime()->sortable(),
                TextColumn::make('actor.email')->label(__('By'))->placeholder(__('System'))->searchable(),
                TextColumn::make('action')->label(__('Action'))->badge()->searchable(),
                TextColumn::make('subject_id')->label('#'),
                TextColumn::make('changes')->label(__('Changes'))->limit(120)->wrap()
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE) : (string) $state),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([SelectFilter::make('subject_type')->label(__('Type'))->options(fn () => AuditLog::query()->distinct()->pluck('subject_type', 'subject_type')->all())])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}
