<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RecurringPrivateLessons\Schemas;

use App\Enums\CourseSemester;
use App\Enums\RecurringPrivateLessonStatus;
use App\Enums\ScheduleFrequency;
use App\Models\RecurringPrivateLesson;
use App\Models\Student;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class RecurringPrivateLessonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Household & Lesson')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('user_id')
                            ->label('Household')
                            ->searchable(['first_name', 'last_name', 'email'])
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->where(function (Builder $query) use ($search): void {
                                    $query
                                        ->where('first_name', 'like', "%{$search}%")
                                        ->orWhere('last_name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%");
                                })
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (User $user): array => [$user->id => $user->displayName().' · '.$user->email])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->find($value)?->displayName())
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live()
                            ->disabledOn('edit'),
                        Select::make('student_id')
                            ->label('Dancer')
                            ->options(fn (Get $get): array => Student::query()
                                ->where('user_id', $get('user_id'))
                                ->orderBy('first_name')
                                ->orderBy('last_name')
                                ->get()
                                ->mapWithKeys(fn (Student $student): array => [$student->id => $student->displayName()])
                                ->all())
                            ->selectablePlaceholder(false)
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('course_name')
                            ->label('Lesson Name / Style')
                            ->required()
                            ->maxLength(255)
                            ->formatStateUsing(fn (mixed $state, ?RecurringPrivateLesson $record): mixed => $record?->course->name ?? $state),
                        Select::make('semester')
                            ->options(CourseSemester::class)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->default(CourseSemester::Fall->value)
                            ->formatStateUsing(fn (mixed $state, ?RecurringPrivateLesson $record): mixed => $record?->course->semester ?? $state),
                        Select::make('teacher_ids')
                            ->label('Teachers')
                            ->multiple()
                            ->preload()
                            ->options(fn (): array => User::query()
                                ->whereHas('roles', fn (Builder $query): Builder => $query->whereIn('name', ['teacher', 'owner', 'super_admin']))
                                ->orderBy('first_name')
                                ->orderBy('last_name')
                                ->get()
                                ->mapWithKeys(fn (User $user): array => [$user->id => $user->displayName()])
                                ->all())
                            ->required()
                            ->formatStateUsing(fn (mixed $state, ?RecurringPrivateLesson $record): mixed => $record?->course->teachers->pluck('id')->all() ?? $state),
                        TextInput::make('lesson_price_dollars')
                            ->label('Price Per Lesson')
                            ->prefix('$')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0.01)
                            ->required()
                            ->formatStateUsing(fn (mixed $state, ?RecurringPrivateLesson $record): mixed => $record === null
                                ? $state
                                : number_format($record->lesson_price / 100, 2, '.', '')),
                        Textarea::make('course_description')
                            ->label('Description')
                            ->helperText('This description is visible to the dancer/parent.')
                            ->columnSpanFull()
                            ->formatStateUsing(fn (mixed $state, ?RecurringPrivateLesson $record): mixed => $record?->course->description ?? $state),
                        Select::make('status')
                            ->options(RecurringPrivateLessonStatus::class)
                            ->helperText('Completed and cancelled series stop billing, payment reminders, rescheduling, and new lesson synchronization. Paid lessons remain available for individual resolution.')
                            ->selectablePlaceholder(false)
                            ->required()
                            ->default(RecurringPrivateLessonStatus::Active->value)
                            ->visibleOn('edit'),
                    ]),
                Section::make('Semester Schedule')
                    ->description('Holidays are skipped automatically. Every lesson must be scheduled more than 24 hours in advance.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->visibleOn('create')
                    ->schema([
                        DateTimePicker::make('starts_at')
                            ->label('First Lesson')
                            ->required()
                            ->seconds(false)
                            ->minDate(now()->addDay()->startOfMinute()->addMinute()),
                        TextInput::make('duration_minutes')
                            ->label('Duration (minutes)')
                            ->numeric()
                            ->minValue(1)
                            ->default(60)
                            ->required(),
                        DatePicker::make('repeat_through')
                            ->label('Repeat Through')
                            ->required(),
                        Select::make('repeat_frequency')
                            ->label('Frequency')
                            ->options([
                                ScheduleFrequency::Weekly->value => 'Weekly',
                                ScheduleFrequency::Biweekly->value => 'Biweekly',
                            ])
                            ->default(ScheduleFrequency::Weekly->value)
                            ->selectablePlaceholder(false)
                            ->required(),
                    ]),
            ]);
    }
}
