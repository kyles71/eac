<?php

namespace App\Filament\Admin\Resources\Forms\Schemas;

use App\Enums\FormTypes;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FormForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Select::make('form_type')
                    ->options(FormTypes::class)
                    ->required(),
                Toggle::make('can_update')
                    ->required(),
                DateTimePicker::make('valid_until'),
            ]);
    }
}
