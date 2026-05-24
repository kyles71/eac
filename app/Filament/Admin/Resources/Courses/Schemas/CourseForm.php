<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Schemas;

use App\Enums\FormTypes;
use App\Models\Form;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
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
                        ->where('form_type', FormTypes::StudentWaiver)
                        ->orderBy('valid_until', 'desc')
                        ->first()
                        ?->id
                    ),
                Section::make('Media')
                    ->columns(3)
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('images')
                            ->collection('images')
                            ->disk(MediaDisks::public())
                            ->visibility('public')
                            ->multiple()
                            ->reorderable()
                            ->image(),
                        SpatieMediaLibraryFileUpload::make('documents')
                            ->collection('documents')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            ->multiple()
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ]),
                        SpatieMediaLibraryFileUpload::make('videos')
                            ->collection('videos')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            ->multiple()
                            ->acceptedFileTypes(['video/mp4', 'video/webm', 'video/quicktime']),
                    ]),
            ]);
    }
}
