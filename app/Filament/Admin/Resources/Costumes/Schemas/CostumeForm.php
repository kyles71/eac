<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Schemas;

use App\Support\MediaDisks;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CostumeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Costume')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                    ]),
                Section::make('Media')
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('images')
                            ->collection('images')
                            ->disk(MediaDisks::public())
                            ->visibility('public')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
