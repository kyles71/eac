<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Schemas;

use App\Actions\Events\ManageEventTeacherAssignments;
use App\Enums\EventTeacherAssignmentMode;
use App\Enums\ScheduleFrequency;
use App\Exceptions\ScheduleRecurrenceLimitExceededException;
use App\Exceptions\TeacherScheduleConflictException;
use App\Filament\Shared\Schemas\PeopleAndGroupsPicker;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Services\EventScheduleConflictReviewService;
use App\Services\HolidayConflictService;
use App\Support\ApplicationDateTime;
use App\Support\LocationNameGuidance;
use App\Support\MediaDisks;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class EventForm
{
    public static function configure(Schema $schema, ?int $course_id = null): Schema
    {
        return $schema
            ->components(self::components($course_id));
    }

    public static function components(?int $course_id = null): array
    {
        return [
            Section::make('Event')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->label('Event Name')
                        ->helperText(LocationNameGuidance::HELP_TEXT)
                        ->required()
                        ->maxLength(255),
                    Select::make('course_id')
                        ->label('Course')
                        ->hidden(fn (): bool => $course_id !== null)
                        ->relationship(
                            'course',
                            'name',
                            modifyQueryUsing: fn (Builder $query): Builder => self::scopeCourseOptions($query),
                        )
                        ->searchable()
                        ->preload()
                        ->required(fn (): bool => $course_id === null && self::currentUserHasCourseRestrictedAccess())
                        ->rules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! self::currentUserCanUseCourse($value)) {
                                    $fail('You may only assign events to courses that you teach.');
                                }
                            },
                        ])
                        ->afterStateUpdated(fn (Set $set): mixed => self::resetScheduleConflictReview($set))
                        ->live(),
                    Select::make('teacher_assignment_mode')
                        ->label('Teacher Assignments')
                        ->options(EventTeacherAssignmentMode::class)
                        ->default($course_id === null
                            ? EventTeacherAssignmentMode::Custom->value
                            : EventTeacherAssignmentMode::CourseDefaults->value)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->visible(fn (Get $get): bool => filled($get('course_id')) || $course_id !== null)
                        ->afterStateUpdated(fn (Set $set): mixed => self::resetScheduleConflictReview($set))
                        ->live(),
                    Select::make('teacher_ids')
                        ->label('Teachers')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => User::query()
                            ->whereHas('roles', fn (Builder $query): Builder => $query
                                ->whereIn('name', ['teacher', 'owner', 'super_admin']))
                            ->orderBy('first_name')
                            ->orderBy('last_name')
                            ->get()
                            ->mapWithKeys(fn (User $teacher): array => [$teacher->id => $teacher->fullName])
                            ->all())
                        ->default(fn (): array => $course_id === null
                            ? []
                            : \App\Models\Course::query()->find($course_id)?->teachers()->pluck('users.id')->all() ?? [])
                        ->required(fn (Get $get): bool => (filled($get('course_id')) || $course_id !== null)
                            && self::teacherAssignmentMode($get('teacher_assignment_mode')) === EventTeacherAssignmentMode::Custom)
                        ->visible(fn (Get $get): bool => ! filled($get('course_id'))
                            || self::teacherAssignmentMode($get('teacher_assignment_mode')) === EventTeacherAssignmentMode::Custom)
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => self::resetScheduleConflictReview($set))
                        ->loadStateFromRelationshipsUsing(function (Select $component, ?Event $record): void {
                            $component->state($record?->teachers()->pluck('users.id')->all() ?? []);
                        })
                        ->saveRelationshipsUsing(function (?Event $record, array $state, Get $get): void {
                            if (! $record instanceof Event) {
                                return;
                            }

                            self::assignTeachers(
                                event: $record,
                                teacherIds: $state,
                                scheduleConflictOverrideBy: self::scheduleConflictOverrideActor([
                                    'allow_teacher_schedule_conflicts' => $get('allow_teacher_schedule_conflicts'),
                                ]),
                            );
                        })
                        ->dehydrated(false),
                    TextInput::make('focus')
                        ->label('Focus / Theme (Public)'),
                    Textarea::make('description')
                        ->label('Public Description')
                        ->columnSpanFull(),
                    Textarea::make('details')
                        ->label('Lesson Plan (Staff Only)')
                        ->visible(fn (?Event $record): bool => self::canViewPrivateContent($record))
                        ->dehydrated(fn (?Event $record): bool => self::canViewPrivateContent($record))
                        ->columnSpanFull(),
                ]),
            Section::make('Schedule')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    DateTimePicker::make('start_time')
                        ->label('Starts At')
                        ->required()
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $holiday = app(HolidayConflictService::class)->conflictingHolidayFor(
                                    ApplicationDateTime::tryFromDisplayInput($value) ?? $value,
                                    $get('end_time'),
                                    $get('course_id'),
                                );

                                if ($holiday !== null) {
                                    $fail("This event overlaps the \"{$holiday->name}\" holiday.");
                                }
                            },
                            fn (Get $get, Set $set, ?Event $record, string $operation): Closure => function (
                                string $attribute,
                                mixed $value,
                                Closure $fail,
                            ) use ($course_id, $get, $operation, $record, $set): void {
                                self::validateTeacherScheduleConflicts(
                                    state: [
                                        'name' => $get('name'),
                                        'course_id' => $get('course_id'),
                                        'teacher_assignment_mode' => $get('teacher_assignment_mode'),
                                        'teacher_ids' => $get('teacher_ids'),
                                        'start_time' => ApplicationDateTime::tryFromDisplayInput($value) ?? $value,
                                        'end_time' => $get('end_time'),
                                        'repeat_frequency' => $get('repeat_frequency'),
                                        'repeat_through' => $get('repeat_through'),
                                    ],
                                    get: $get,
                                    set: $set,
                                    fail: $fail,
                                    record: $record,
                                    fixedCourseId: $course_id,
                                    includeRecurrences: $operation === 'create',
                                );
                            },
                        ])
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => self::resetScheduleConflictReview($set)),
                    DateTimePicker::make('end_time')
                        ->label('Ends At')
                        ->required()
                        ->afterOrEqual('start_time')
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => self::resetScheduleConflictReview($set)),
                    Select::make('repeat_frequency')
                        ->label('Repeat')
                        ->placeholder('Does not repeat')
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => self::resetScheduleConflictReview($set))
                        ->visible(fn (string $operation): bool => $operation === 'create')
                        ->enum(ScheduleFrequency::class)
                        ->options(ScheduleFrequency::class),
                    DatePicker::make('repeat_through')
                        ->label('Repeat Through')
                        ->required(fn (Get $get): bool => filled($get('repeat_frequency')))
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => self::resetScheduleConflictReview($set))
                        ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && filled($get('repeat_frequency'))),
                    Hidden::make('teacher_schedule_conflicts')
                        ->default([])
                        ->dehydrated(false),
                    Hidden::make('teacher_schedule_conflict_fingerprint')
                        ->dehydrated(false),
                    Callout::make('Teacher schedule conflicts')
                        ->description('The proposed teacher assignments overlap existing commitments. Adjust the schedule or review every conflict before continuing.')
                        ->danger()
                        ->extraAttributes(['role' => 'alert'])
                        ->footer([
                            TextEntry::make('teacher_schedule_conflict_details')
                                ->hiddenLabel()
                                ->state(fn (Get $get): array => is_array($get('teacher_schedule_conflicts'))
                                    ? $get('teacher_schedule_conflicts')
                                    : [])
                                ->bulleted(),
                        ])
                        ->visible(fn (Get $get): bool => self::hasScheduleConflictReview($get))
                        ->columnSpanFull(),
                    Checkbox::make('allow_teacher_schedule_conflicts')
                        ->label('Allow these overlapping teacher assignments')
                        ->helperText('This confirmation applies only to this Create or Save submission.')
                        ->accepted(fn (Get $get): bool => self::hasScheduleConflictReview($get))
                        ->validationMessages([
                            'accepted' => 'Confirm that you want to allow these overlapping teacher assignments.',
                        ])
                        ->visible(fn (Get $get): bool => self::hasScheduleConflictReview($get)
                            && self::currentUserCanOverrideScheduleConflicts())
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ]),
            Section::make('Visibility')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('calendar_id')
                        ->label('Calendar')
                        ->preload()
                        ->relationship('calendar', 'name', function ($query): void {
                            $user = auth()->user();

                            $query
                                ->where('slug', '!=', Calendar::SLUG_MY)
                                ->when($user instanceof User, fn ($query) => $query->assignableBy($user))
                                ->orderBy('id', 'asc');
                        })
                        ->required()
                        ->live()
                        ->default(fn (): ?int => Calendar::query()
                            ->where('slug', Calendar::SLUG_EAC)
                            ->value('id')),
                    Select::make('excluded_user_ids')
                        ->label('Excluded Staff / Users')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->visible(fn (?Event $record): bool => self::canViewPrivateContent($record))
                        ->options(fn (Get $get): array => self::excludedUserOptions((int) $get('calendar_id')))
                        ->loadStateFromRelationshipsUsing(function (Select $component, ?Event $record): void {
                            $component->state($record?->excludedUsers()
                                ->pluck('users.id')
                                ->map(fn (int $id): string => (string) $id)
                                ->all() ?? []);
                        })
                        ->saveRelationshipsUsing(function (?Event $record, array $state): void {
                            if (! $record instanceof Event) {
                                return;
                            }

                            $calendar = $record->calendar;

                            if (! $calendar instanceof Calendar) {
                                return;
                            }

                            $userIds = self::excludedUserQuery($calendar)
                                ->whereIn('id', $state)
                                ->pluck('id')
                                ->all();

                            $record->excludedUsers()->sync($userIds);
                        })
                        ->dehydrated(false),
                ]),
            PeopleAndGroupsPicker::eventInvitations($course_id),
            Section::make('Media')
                ->columns(2)
                ->collapsed()
                ->columnSpanFull()
                ->visible(fn (?Event $record): bool => self::canViewPrivateContent($record))
                ->schema([
                    SpatieMediaLibraryFileUpload::make('images')
                        ->label('Images')
                        ->collection('images')
                        ->disk(MediaDisks::private())
                        ->visibility('private')
                        ->multiple()
                        ->reorderable()
                        ->image(),
                    SpatieMediaLibraryFileUpload::make('documents')
                        ->label('Documents')
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
                ]),
        ];
    }

    /**
     * @param  array<int, mixed>  $teacherIds
     */
    public static function assignTeachers(
        Event $event,
        array $teacherIds,
        ?User $scheduleConflictOverrideBy = null,
    ): Event {
        try {
            if ($event->course_id !== null
                && $event->teacher_assignment_mode === EventTeacherAssignmentMode::CourseDefaults) {
                return app(ManageEventTeacherAssignments::class)->initializeCourseEvent(
                    $event,
                    $scheduleConflictOverrideBy,
                );
            }

            return app(ManageEventTeacherAssignments::class)->assignCustom(
                $event,
                array_map(intval(...), $teacherIds),
                $scheduleConflictOverrideBy,
            );
        } catch (TeacherScheduleConflictException $exception) {
            $viewer = auth()->user();

            throw ValidationException::withMessages([
                'start_time' => $exception->messageFor($viewer instanceof User ? $viewer : null),
            ]);
        }
    }

    /** @param array<string, mixed> $state */
    public static function scheduleConflictOverrideActor(array $state): ?User
    {
        if (! filter_var($state['allow_teacher_schedule_conflicts'] ?? false, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $user = auth()->user();

        return $user instanceof User && self::currentUserCanOverrideScheduleConflicts()
            ? $user
            : null;
    }

    private static function excludedUserOptions(int $calendarId): array
    {
        $calendar = Calendar::query()
            ->find($calendarId);

        return self::excludedUserQuery($calendar)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->getFilamentName()])
            ->all();
    }

    /** @return Builder<User> */
    private static function excludedUserQuery(?Calendar $calendar): Builder
    {
        return $calendar?->usersWithAccess() ?? User::query()->whereRaw('0 = 1');
    }

    private static function canViewPrivateContent(?Event $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $record instanceof Event
            ? $user->can('view', $record)
            : $user->can('Create:Event');
    }

    private static function currentUserHasCourseRestrictedAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasCourseRestrictedAdminAccess();
    }

    private static function teacherAssignmentMode(mixed $mode): ?EventTeacherAssignmentMode
    {
        return $mode instanceof EventTeacherAssignmentMode
            ? $mode
            : (is_string($mode) ? EventTeacherAssignmentMode::tryFrom($mode) : null);
    }

    private static function currentUserCanUseCourse(mixed $courseId): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasCourseRestrictedAdminAccess()) {
            return true;
        }

        if (! is_numeric($courseId)) {
            return false;
        }

        return $user->teachingCourses()
            ->whereKey((int) $courseId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function validateTeacherScheduleConflicts(
        array $state,
        Get $get,
        Set $set,
        Closure $fail,
        ?Event $record,
        ?int $fixedCourseId,
        bool $includeRecurrences,
    ): void {
        try {
            $conflicts = app(EventScheduleConflictReviewService::class)->conflictsForFormState(
                state: $state,
                record: $record,
                fixedCourseId: $fixedCourseId,
                includeRecurrences: $includeRecurrences,
            );
        } catch (ScheduleRecurrenceLimitExceededException $exception) {
            self::resetScheduleConflictReview($set);
            $fail($exception->getMessage());

            return;
        }

        if ($conflicts->isEmpty()) {
            self::resetScheduleConflictReview($set);

            return;
        }

        $exception = new TeacherScheduleConflictException($conflicts);
        $viewer = auth()->user();
        $viewer = $viewer instanceof User ? $viewer : null;
        $reviewedFingerprint = $get('teacher_schedule_conflict_fingerprint');
        $isConfirmed = filter_var($get('allow_teacher_schedule_conflicts'), FILTER_VALIDATE_BOOL);
        $isCurrentReview = is_string($reviewedFingerprint)
            && hash_equals($exception->fingerprint(), $reviewedFingerprint);

        if ($isConfirmed && $isCurrentReview && self::currentUserCanOverrideScheduleConflicts()) {
            return;
        }

        $set('teacher_schedule_conflicts', $exception->displayMessages($viewer));
        $set('teacher_schedule_conflict_fingerprint', $exception->fingerprint());
        $set('allow_teacher_schedule_conflicts', false);

        $fail($exception->messageFor($viewer));
    }

    private static function resetScheduleConflictReview(Set $set): mixed
    {
        $set('teacher_schedule_conflicts', []);
        $set('teacher_schedule_conflict_fingerprint', null);

        return $set('allow_teacher_schedule_conflicts', false);
    }

    private static function hasScheduleConflictReview(Get $get): bool
    {
        return is_array($get('teacher_schedule_conflicts'))
            && $get('teacher_schedule_conflicts') !== [];
    }

    private static function currentUserCanOverrideScheduleConflicts(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('OverrideScheduleConflicts:Event');
    }

    private static function scopeCourseOptions(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasCourseRestrictedAdminAccess()) {
            return $query;
        }

        return $query->whereHas(
            'teachers',
            fn (Builder $query): Builder => $query->whereKey($user->id),
        );
    }
}
