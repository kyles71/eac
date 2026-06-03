<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class FormInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Form')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('form_type')
                            ->label('Type')
                            ->badge(),
                        IconEntry::make('can_update')
                            ->label('Can Be Updated')
                            ->boolean(),
                        TextEntry::make('valid_until')
                            ->label('Valid Until')
                            ->dateTime()
                            ->placeholder('-'),
                    ]),
                Section::make('Record')
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('updated_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
