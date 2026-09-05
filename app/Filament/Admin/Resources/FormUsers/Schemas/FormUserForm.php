<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FormUsers\Schemas;

use App\Support\Filament\HouseholdStudentSelect;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class FormUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Form Assignment')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('form_id')
                            ->label('Form')
                            ->relationship('form', 'name')
                            ->selectablePlaceholder(false)
                            ->required(),
                        HouseholdStudentSelect::user()
                            ->label('User')
                            ->selectablePlaceholder(false)
                            ->required(),
                        HouseholdStudentSelect::student()
                            ->label('Student'),
                    ]),
            ]);
    }
}
