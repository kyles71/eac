<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Schemas;

use App\Enums\FormTypes;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class FormForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Form')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required(),
                        Select::make('form_type')
                            ->label('Type')
                            ->options(FormTypes::class)
                            ->required(),
                        Toggle::make('can_update')
                            ->label('Can Be Updated')
                            ->default(true)
                            ->required(),
                        DateTimePicker::make('valid_until')
                            ->label('Valid Until'),
                    ]),
            ]);
    }
}
