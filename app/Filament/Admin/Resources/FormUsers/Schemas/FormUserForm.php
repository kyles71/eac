<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FormUsers\Schemas;

use App\Models\Student;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

final class FormUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('form_id')
                    ->relationship('form', 'name')
                    ->required(),
                Select::make('user_id')
                    ->searchableRelationship(
                        name: 'user',
                        searchColumns: ['first_name', 'last_name'],
                        labelFromRecord: fn (User $user): string => $user->fullName,
                        orderBy: ['first_name', 'last_name'],
                    )
                    ->required(),
                Select::make('student_id')
                    ->searchableRelationship(
                        name: 'student',
                        searchColumns: ['first_name', 'last_name'],
                        labelFromRecord: fn (Student $student): string => $student->fullName,
                        orderBy: ['first_name', 'last_name'],
                    ),
            ]);
    }
}
