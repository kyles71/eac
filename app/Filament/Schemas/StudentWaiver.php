<?php

namespace App\Filament\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StudentWaiver
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('medical_conditions')
                    ->label('Medical Conditions')
                    ->required(),
                TextInput::make('allergies')
                    ->label('Allergies')
                    ->required(),
                Repeater::make('emergency_contacts')
                    ->label('Emergency Contacts')
                    ->columnSpanFull()
                    ->columns(2)
                    ->relationship('emergencyContacts')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required(),
                        TextInput::make('relationship')
                            ->label('Relationship')
                            ->required(),
                        TextInput::make('phone_number')
                            ->label('Phone Number')
                            ->required(),
                        TextInput::make('email'),
                    ])
                    ->minItems(1)
                    ->defaultItems(1)
                    ->required(),
            ]);
    }
}
