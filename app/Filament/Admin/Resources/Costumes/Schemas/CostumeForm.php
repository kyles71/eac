<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Schemas;

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
            ]);
    }
}
