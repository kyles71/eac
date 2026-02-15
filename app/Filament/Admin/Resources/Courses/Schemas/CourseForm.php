<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Schemas;

use App\Enums\FormTypes;
use App\Models\Form;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class CourseForm
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
                // SpatieTagsInput::make('tags'),
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
                Select::make('courseForms')
                    ->label('Forms')
                    ->multiple()
                    ->preload()
                    ->relationship(
                        name: 'forms',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->isActive()->orderBy('name'),
                    )
                    ->default(Form::query()
                        ->isActive()
                        ->where('form_type', FormTypes::STUDENT_WAIVER)
                        ->orderBy('valid_until', 'desc')
                        ->first()
                        ?->id
                    ),
            ]);
    }
}
