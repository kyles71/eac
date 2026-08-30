<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\StaffNotes\Schemas;

use App\Support\MediaDisks;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class StaffNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Staff Note')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('note')
                            ->required()
                            ->rows(8)
                            ->columnSpanFull(),
                        SpatieMediaLibraryFileUpload::make('documents')
                            ->collection('documents')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            ->multiple()
                            ->preserveFilenames()
                            ->downloadable()
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'image/jpeg',
                                'image/png',
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
