<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Domain\Entitlements\EntitlementService;
use App\Enums\EntitlementSource;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\LocalizationPackage;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Package ownership. Manual grants/revocations are for support cases and are audit-logged. */
class EntitlementsRelationManager extends RelationManager
{
    protected static string $relationship = 'entitlements';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('Packages owned');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('package.game.title')->label(__('Game')),
                TextColumn::make('package.name')->label(__('Package')),
                TextColumn::make('source')->label(__('Source'))->badge()->formatStateUsing(fn ($state) => $state->value),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn ($state) => $state === 'active' ? 'success' : 'danger'),
                TextColumn::make('granted_at')->label(__('Granted'))->dateTime(),
                TextColumn::make('revoked_reason')->label(__('Revoked because'))->placeholder('—'),
            ])
            ->headerActions([
                Action::make('grant')->label(__('Grant package'))->icon(Heroicon::OutlinedGift)
                    ->schema([
                        Select::make('package_id')->label(__('Package'))->required()->searchable()
                            ->options(fn () => LocalizationPackage::with('game')->get()->mapWithKeys(fn ($p) => [$p->id => $p->game->title.' — '.$p->name])),
                        TextInput::make('reason')->label(__('Reason'))->required(),
                    ])
                    ->action(function (array $data) {
                        $user = $this->getOwnerRecord();
                        $grant = app(EntitlementService::class)->grant($user, LocalizationPackage::with('inclusions')->findOrFail($data['package_id']), EntitlementSource::Admin);
                        AuditLog::record('entitlement.granted', $grant, ['reason' => $data['reason']]);
                        Notification::make()->title(__('Granted'))->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('revoke')->label(__('Revoke'))->color('danger')->icon(Heroicon::OutlinedNoSymbol)
                    ->visible(fn (Entitlement $record) => $record->status === 'active' && $record->parent_entitlement_id === null)
                    ->schema([TextInput::make('reason')->label(__('Reason'))->required()])
                    ->requiresConfirmation()
                    ->action(function (Entitlement $record, array $data) {
                        app(EntitlementService::class)->revoke($record, 'admin');
                        AuditLog::record('entitlement.revoked', $record, ['reason' => $data['reason']]);
                    }),
            ])
            ->defaultSort('granted_at', 'desc');
    }
}
