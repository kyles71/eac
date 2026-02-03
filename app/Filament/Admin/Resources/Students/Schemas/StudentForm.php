<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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
                    ->relationship('user', 'id', fn (Builder $query) => $query->orderBy('first_name')->orderBy('last_name'))
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->first_name} {$record->last_name}")
                    ->searchable(['first_name', 'last_name']),
            ]);
    }
}
