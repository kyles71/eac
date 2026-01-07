<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('capacity')
                    ->required()
                    ->numeric()
                    ->default(10),
                DateTimePicker::make('start_time')
                    ->required(),
                TextInput::make('duration')
                    ->required()
                    ->numeric()
                    ->default(60),
                Select::make('teacher_id')
                    ->preload()
                    ->searchable()
                    // ->createOptionForm(UserForm::configure($schema))
                    // ->editOptionForm(User::getForm())
                    ->relationship(
                        name: 'teacher',
                        titleAttribute: 'id',
                        modifyQueryUsing: fn (Builder $query) => $query->orderBy('first_name')->orderBy('last_name'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (User $user) => $user->fullName),
                TextInput::make('guest_teacher'),
                // SpatieTagsInput::make('tags'),
            ]);
    }
}
