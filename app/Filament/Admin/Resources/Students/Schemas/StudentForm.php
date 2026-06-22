<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\Schemas;

use App\Models\Student;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class StudentForm
{
    public static function configure(Schema $schema, $user_id = null): Schema
    {
        return $schema
            ->components([
                Section::make('Student')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('first_name')
                            ->required(),
                        TextInput::make('last_name')
                            ->required(),
                        TextInput::make('nickname'),
                        DatePicker::make('birthdate')
                            ->required()
                            ->maxDate(today()),
                        Select::make('user_id')
                            ->label('Parent / User')
                            ->hidden(fn (): bool => $user_id !== null)
                            ->userRelationship(),
                    ]),
                Section::make('Tags')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        SpatieTagsInput::make('tags')
                            ->label('Student Tags')
                            ->type(Student::GENERAL_TAG_TYPE),
                    ]),
            ]);
    }
}
