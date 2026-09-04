<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BoardItems\Schemas;

use App\Filament\Admin\Resources\Boards\BoardResource;
use App\Models\BoardItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class BoardItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    Section::make('Details')
                        ->columns(2)
                        ->columnSpan(2)
                        ->schema([
                            TextEntry::make('board.name')
                                ->label('Board')
                                ->url(fn (BoardItem $record): string => BoardResource::getUrl('board', ['record' => $record->board])),
                            TextEntry::make('stage.name')
                                ->label('Stage')
                                ->badge()
                                ->color(fn (BoardItem $record): string => $record->stage->color),
                            TextEntry::make('type')
                                ->badge(),
                            TextEntry::make('priority')
                                ->badge(),
                            TextEntry::make('description')
                                ->html()
                                ->prose()
                                ->placeholder('No description provided.')
                                ->columnSpanFull(),
                            RepeatableEntry::make('attachments')
                                ->state(fn (BoardItem $record) => $record->getMedia('attachments'))
                                ->table([
                                    TableColumn::make('File'),
                                    TableColumn::make('Size'),
                                    TableColumn::make('Added'),
                                ])
                                ->schema([
                                    TextEntry::make('file_name')
                                        ->icon(Heroicon::OutlinedArrowDownTray)
                                        ->url(fn (Media $record): string => route('admin.board-items.attachments.download', [
                                            'boardItem' => $record->model_id,
                                            'media' => $record->id,
                                        ])),
                                    TextEntry::make('human_readable_size')
                                        ->label('Size'),
                                    TextEntry::make('created_at')
                                        ->dateTime(),
                                ])
                                ->contained(false)
                                ->columnSpanFull(),
                        ]),
                    Section::make('Workflow')
                        ->columnSpan(1)
                        ->schema([
                            TextEntry::make('assignees.full_name')
                                ->label('Assignees')
                                ->listWithLineBreaks()
                                ->placeholder('Unassigned'),
                            TextEntry::make('due_date')
                                ->date()
                                ->placeholder('No due date'),
                            TextEntry::make('related_url')
                                ->label('Related link')
                                ->url(fn (?string $state): ?string => $state)
                                ->openUrlInNewTab()
                                ->placeholder('None'),
                            TextEntry::make('creator.full_name')
                                ->label('Created by')
                                ->placeholder('Deleted user'),
                            TextEntry::make('created_at')
                                ->label('Created')
                                ->dateTime(),
                            TextEntry::make('archived_at')
                                ->label('Archived')
                                ->dateTime()
                                ->visible(fn (BoardItem $record): bool => $record->isArchived()),
                        ]),
                ]),
        ]);
    }
}
