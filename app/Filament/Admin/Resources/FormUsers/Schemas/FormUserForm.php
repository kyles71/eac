<?php

namespace App\Filament\Admin\Resources\FormUsers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class FormUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('form_id')
                    ->relationship('form', 'name')
                    ->required(),
                Select::make('user_id')
                    ->relationship('user', 'id')
                    ->required(),
                Select::make('student_id')
                    ->relationship('student', 'id'),
            ]);
    }
}
