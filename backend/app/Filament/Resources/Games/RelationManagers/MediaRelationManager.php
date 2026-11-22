<?php

namespace App\Filament\Resources\Games\RelationManagers;

use App\Filament\Support\Translatable;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('Screenshots of translated subtitles');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('path')->label(__('Image'))->image()->disk('public')->directory('games/media')->required()->columnSpanFull(),
            Select::make('kind')->label(__('Kind'))->options(['screenshot' => __('Screenshot'), 'subtitle_preview' => __('Subtitle preview')])->default('subtitle_preview')->required(),
            TextInput::make('sort_order')->label(__('Order'))->numeric()->default(0),
            Translatable::input('caption', __('Caption'), required: false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('path')->disk('public')->height(56),
                TextColumn::make('caption')->label(__('Caption')),
                TextColumn::make('kind')->label(__('Kind'))->badge(),
            ])
            ->reorderable('sort_order')
            ->headerActions([CreateAction::make()])
            ->recordActions([
                EditAction::make()->mutateRecordDataUsing(fn (array $data, $record) => [...$data, 'caption' => $record->getTranslations('caption')]),
                DeleteAction::make(),
            ]);
    }
}
