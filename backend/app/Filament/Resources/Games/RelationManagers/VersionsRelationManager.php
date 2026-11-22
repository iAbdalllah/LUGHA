<?php

namespace App\Filament\Resources\Games\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('Supported game versions');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('version')->label(__('Version'))->required()->maxLength(40),
            DatePicker::make('released_at')->label(__('Released')),
            Textarea::make('notes')->label(__('Notes'))->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->label(__('Version'))->fontFamily('mono'),
                TextColumn::make('released_at')->label(__('Released'))->date(),
                TextColumn::make('notes')->label(__('Notes'))->limit(60),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
