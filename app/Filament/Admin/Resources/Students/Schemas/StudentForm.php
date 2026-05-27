<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class StudentForm
{
    public static function configure(Schema $schema, $user_id = null): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->required(),
                TextInput::make('last_name')
                    ->required(),
                Select::make('user_id')
                    ->hidden(fn (): bool => $user_id !== null)
                    ->preload()
                    ->searchableRelationship(
                        name: 'user',
                        searchColumns: ['first_name', 'last_name'],
                        labelFromRecord: fn (User $user): string => $user->fullName,
                        orderBy: ['first_name', 'last_name'],
                    ),
            ]);
    }
}
