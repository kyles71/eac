<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class ShowcaseParticipation
{
    public static function configure(Schema $schema, bool $withRelationships = true): Schema
    {
        return $schema
            ->components([
                Toggle::make('is_participating')
                    ->label('Is Participating')
                    ->required(),
            ]);
    }
}
