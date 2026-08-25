<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\StaffNotes\Schemas;

use App\Models\StaffNote;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class StaffNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Staff Note')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('author.full_name')
                            ->label('Author')
                            ->placeholder('Deleted user'),
                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime(),
                        TextEntry::make('note')
                            ->columnSpanFull(),
                        RepeatableEntry::make('documents')
                            ->state(fn (StaffNote $record) => $record->getMedia('documents'))
                            ->table([
                                TableColumn::make('File'),
                                TableColumn::make('Size'),
                                TableColumn::make('Added'),
                            ])
                            ->schema([
                                TextEntry::make('file_name')
                                    ->icon(Heroicon::OutlinedArrowDownTray)
                                    ->url(fn (Media $record): string => route(
                                        'admin.staff-notes.documents.download',
                                        ['staffNote' => $record->model_id, 'media' => $record->id],
                                    )),
                                TextEntry::make('human_readable_size')
                                    ->label('Size'),
                                TextEntry::make('created_at')
                                    ->dateTime(),
                            ])
                            ->contained(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
