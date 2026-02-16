<?php

namespace App\Filament\Schemas;

use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ShowcaseParticipation
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('is_participating')
                    ->label('Is Participating')
                    ->required(),
            ]);
    }
}
