<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;

final class StudentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('first_name'),
                TextEntry::make('last_name'),
                TextEntry::make('user.full_name')
                    ->label('User\'s Name')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
                // Fieldset::make('Medical Information')
                //     ->columnSpanFull()
                //     ->columns(3)
                //     ->schema([
                //         TextEntry::make('healthInfo.medications'),
                //         TextEntry::make('healthInfo.behavior'),
                //         TextEntry::make('healthInfo.allergies'),
                //     ]),
            ]);
    }
}
