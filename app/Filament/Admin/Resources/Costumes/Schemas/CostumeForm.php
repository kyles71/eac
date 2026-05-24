<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Schemas;

use App\Support\MediaDisks;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class CostumeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                SpatieMediaLibraryFileUpload::make('images')
                    ->collection('images')
                    ->disk(MediaDisks::public())
                    ->visibility('public')
                    ->multiple()
                    ->reorderable()
                    ->image()
                    ->columnSpanFull(),
            ]);
    }
}
