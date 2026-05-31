<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Schemas;

use App\Enums\FormTypes;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Form;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

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
                DateTimePicker::make('start_time')
                    ->required(),
                TextInput::make('duration')
                    ->required()
                    ->numeric()
                    ->default(60),
                Select::make('calendar_tag_slugs')
                    ->label('Apply To Calendars')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->options(fn (): array => Calendar::query()
                        ->where('slug', '!=', Calendar::SLUG_MY)
                        ->orderBy('id')
                        ->pluck('name', 'slug')
                        ->all())
                    ->default([Calendar::SLUG_EAC])
                    ->loadStateFromRelationshipsUsing(function (Select $component, ?Course $record): void {
                        $component->state($record?->tagsWithType(Course::CALENDAR_TAG_TYPE)
                            ->pluck('name')
                            ->all() ?? [Calendar::SLUG_EAC]);
                    })
                    ->saveRelationshipsUsing(function (?Course $record, array $state): void {
                        $calendarSlugs = Calendar::query()
                            ->where('slug', '!=', Calendar::SLUG_MY)
                            ->whereIn('slug', $state)
                            ->pluck('slug')
                            ->all();

                        $record?->syncTagsWithType($calendarSlugs, Course::CALENDAR_TAG_TYPE);
                    })
                    ->dehydrated(false)
                    ->columnSpanFull(),
                Select::make('teachers')
                    ->label('Teachers')
                    ->multiple()
                    ->preload()
                    ->searchableRelationship(
                        name: 'teachers',
                        searchColumns: ['first_name', 'last_name'],
                        labelFromRecord: fn (User $user): string => $user->fullName,
                        modifyQueryUsing: fn (Builder $query): Builder => self::scopeTeacherOptions($query),
                        orderBy: ['first_name', 'last_name'],
                    ),
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
                            ->disk(MediaDisks::public())
                            ->visibility('public')
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
                            ->disk(MediaDisks::public())
                            ->visibility('public')
                            ->multiple()
                            ->acceptedFileTypes(['video/mp4', 'video/webm', 'video/quicktime']),
                    ]),
            ]);
    }

    public static function scopeTeacherOptions(Builder $query): Builder
    {
        if (! Role::query()->where('name', 'teacher')->exists()) {
            return $query;
        }

        return $query->role('teacher');
    }
}
