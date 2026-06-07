<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\Students\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class StudentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('last_name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('nickname')
                    ->maxLength(255),
                DatePicker::make('birthdate')
                    ->required()
                    ->maxDate(today()),
            ]);
    }
}
