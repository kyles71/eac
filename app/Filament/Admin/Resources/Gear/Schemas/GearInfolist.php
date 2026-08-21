<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gear\Schemas;

use App\Models\Gear;
use App\Support\MediaDisks;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class GearInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Gear')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('products_count')
                        ->label('Product Listings')
                        ->state(fn (Gear $record): int => $record->products()->count()),
                ]),
            Section::make('Media')
                ->columnSpanFull()
                ->schema([
                    SpatieMediaLibraryImageEntry::make('images')
                        ->collection('images')
                        ->disk(MediaDisks::public())
                        ->visibility('public'),
                ]),
        ]);
    }
}
