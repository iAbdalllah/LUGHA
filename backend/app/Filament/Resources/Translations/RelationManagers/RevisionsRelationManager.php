<?php

namespace App\Filament\Resources\Translations\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('Revision history');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('revision')->label('#'),
                TextColumn::make('target_text')->label(__('Translation'))->wrap()->extraAttributes(['dir' => 'rtl']),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => __('status.translation.'.$state)),
                TextColumn::make('author.name')->label(__('By')),
                TextColumn::make('comment')->label(__('Comment'))->wrap(),
                TextColumn::make('created_at')->label(__('When'))->since(),
            ])
            ->defaultSort('revision', 'desc');
    }
}
