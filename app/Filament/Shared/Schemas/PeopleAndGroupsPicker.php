<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use App\Enums\CalendarAccess;
use App\Models\Calendar;
use App\Models\CalendarAudience;
use App\Models\Course;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

final class PeopleAndGroupsPicker
{
    public static function eventInvitations(?int $courseId): Section
    {
        return self::make(
            heading: 'Direct Invitations',
            listField: 'attendees_list',
            listLabel: 'Invited Attendees',
            fieldPrefix: 'add',
            typeKey: 'attendee_type',
            idKey: 'attendee_id',
            expandCourseRoster: true,
            requiresItems: false,
            loadState: function (Repeater $component, Event $record): void {
                $component->state(self::eventInvitationState($record));
            },
            saveRelationships: fn (Event $record, ?array $state) => self::saveEventInvitations($record, $state),
        )
            ->visible(function (Get $get, ?Event $record) use ($courseId): bool {
                $user = auth()->user();
                $canViewPrivateContent = $user instanceof User
                    && ($record instanceof Event
                        ? $user->can('view', $record)
                        : $user->can('Create:Event'));

                return $canViewPrivateContent
                    && $courseId === null
                    && blank($get('course_id'));
            });
    }

    public static function calendarAudiences(): Section
    {
        return self::make(
            heading: 'Audience',
            listField: 'audiences_list',
            listLabel: 'Allowed People and Groups',
            fieldPrefix: 'add_audience',
            typeKey: 'audience_type',
            idKey: 'audience_id',
            expandCourseRoster: false,
            requiresItems: true,
            loadState: function (Repeater $component, Calendar $record): void {
                $component->state(
                    $record->audiences()
                        ->with('audience')
                        ->get()
                        ->filter(fn (CalendarAudience $audience): bool => self::canSelectModel($audience->audience))
                        ->map(fn (CalendarAudience $audience): array => [
                            'audience_type' => $audience->audience_type,
                            'audience_id' => $audience->audience_id,
                            'label' => self::modelLabel($audience->audience),
                        ])
                        ->all(),
                );
            },
            saveRelationships: fn (Calendar $record, ?array $state) => self::saveCalendarAudiences($record, $state),
        )
            ->description('People gain access immediately, and course roster access stays current as enrollments and teachers change.')
            ->visible(fn (Get $get, ?Calendar $record): bool => ! ($record?->isSystemCalendar() ?? false)
                && CalendarAccess::tryFrom((string) ($get('access') instanceof CalendarAccess ? $get('access')->value : $get('access'))) === CalendarAccess::Restricted);
    }

    public static function saveEventInvitations(Event $event, ?array $state): void
    {
        self::syncMorphRecords(
            query: $event->attendees(),
            state: $state ?? [],
            typeKey: 'attendee_type',
            idKey: 'attendee_id',
            allowedModelClasses: [User::class, Student::class],
        );
    }

    /**
     * @param  list<int>  $suggestedUserIds
     * @return array<int, string>
     */
    public static function selectableUserOptions(array $suggestedUserIds = []): array
    {
        return self::prioritizedModelOptions(self::userQuery(self::authenticatedUser()), $suggestedUserIds);
    }

    /**
     * @param  list<int>  $suggestedStudentIds
     * @return array<int, string>
     */
    public static function selectableStudentOptions(array $suggestedStudentIds = []): array
    {
        return self::prioritizedModelOptions(self::studentQuery(self::authenticatedUser()), $suggestedStudentIds);
    }

    /**
     * Add confirmed people to an event without removing its existing attendees.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $studentIds
     */
    public static function addEventInvitations(Event $event, array $userIds, array $studentIds): void
    {
        $user = self::authenticatedUser();
        $users = self::userQuery($user)->whereKey($userIds)->get();
        $students = self::studentQuery($user)->whereKey($studentIds)->get();

        if ($users->count() !== count(array_unique($userIds))
            || $students->count() !== count(array_unique($studentIds))) {
            throw ValidationException::withMessages([
                'attendees' => 'You may only select attendees within your access.',
            ]);
        }

        foreach ($users->merge($students) as $attendee) {
            $event->attendees()->updateOrCreate([
                'attendee_type' => $attendee->getMorphClass(),
                'attendee_id' => $attendee->getKey(),
            ]);
        }
    }

    /** @return array<int, array{attendee_type: string, attendee_id: int, label: string}> */
    public static function eventInvitationState(Event $event): array
    {
        return $event->attendees()
            ->with('attendee')
            ->get()
            ->filter(fn (EventAttendee $attendee): bool => self::canSelectModel($attendee->attendee))
            ->map(fn (EventAttendee $attendee): array => [
                'attendee_type' => $attendee->attendee_type,
                'attendee_id' => $attendee->attendee_id,
                'label' => self::modelLabel($attendee->attendee),
            ])
            ->all();
    }

    public static function saveCalendarAudiences(Calendar $calendar, ?array $state): void
    {
        if ($calendar->isSystemCalendar() || $calendar->access !== CalendarAccess::Restricted) {
            $calendar->audiences()->delete();

            return;
        }

        self::syncMorphRecords(
            query: $calendar->audiences(),
            state: $state ?? [],
            typeKey: 'audience_type',
            idKey: 'audience_id',
            allowedModelClasses: [User::class, Student::class, Course::class],
        );
    }

    private static function make(
        string $heading,
        string $listField,
        string $listLabel,
        string $fieldPrefix,
        string $typeKey,
        string $idKey,
        bool $expandCourseRoster,
        bool $requiresItems,
        Closure $loadState,
        Closure $saveRelationships,
    ): Section {
        return Section::make($heading)
            ->columns(3)
            ->columnSpanFull()
            ->schema([
                self::modelSelect(
                    field: $fieldPrefix.'_user',
                    label: 'Add User',
                    modelClass: User::class,
                    listField: $listField,
                    typeKey: $typeKey,
                    idKey: $idKey,
                ),
                self::modelSelect(
                    field: $fieldPrefix.'_student',
                    label: 'Add Student',
                    modelClass: Student::class,
                    listField: $listField,
                    typeKey: $typeKey,
                    idKey: $idKey,
                ),
                Select::make($fieldPrefix.'_course')
                    ->label('Add Course Roster')
                    ->loadingMessage('Loading courses...')
                    ->options(fn (): array => self::courseQuery()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set, callable $get) use ($expandCourseRoster, $fieldPrefix, $idKey, $listField, $typeKey): void {
                        if (blank($state)) {
                            return;
                        }

                        $course = self::courseQuery()
                            ->when($expandCourseRoster, fn (Builder $query): Builder => $query->with('students'))
                            ->find($state);

                        if (! $course instanceof Course) {
                            $set($fieldPrefix.'_course', null);

                            return;
                        }

                        $models = $expandCourseRoster ? $course->students : [$course];
                        $items = self::addModels(
                            models: $models,
                            items: $get($listField) ?? [],
                            typeKey: $typeKey,
                            idKey: $idKey,
                        );

                        $set($listField, $items);
                        $set($fieldPrefix.'_course', null);
                    }),
                Repeater::make($listField)
                    ->label($listLabel)
                    ->grid(3)
                    ->columnSpanFull()
                    ->default([])
                    ->required($requiresItems)
                    ->minItems($requiresItems ? 1 : null)
                    ->loadStateFromRelationshipsUsing($loadState)
                    ->saveRelationshipsUsing($saveRelationships)
                    ->dehydrated(false)
                    ->schema([
                        Hidden::make('label'),
                        TextEntry::make('audience_label')
                            ->label('Person or Group')
                            ->state(fn (Get $get): string => (string) ($get('label') ?? 'Unknown')),
                        Hidden::make($typeKey),
                        Hidden::make($idKey),
                    ])
                    ->itemLabel(fn (array $state): string => $state['label'] ?? 'Unknown')
                    ->collapsed()
                    ->collapsible(false)
                    ->reorderable(false)
                    ->addable(false),
            ]);
    }

    /** @param class-string<User|Student> $modelClass */
    private static function modelSelect(
        string $field,
        string $label,
        string $modelClass,
        string $listField,
        string $typeKey,
        string $idKey,
    ): Select {
        return Select::make($field)
            ->label($label)
            ->loadingMessage('Loading...')
            ->options(fn (): array => self::modelQuery($modelClass)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->mapWithKeys(fn (Model $model): array => [$model->getKey() => self::modelLabel($model)])
                ->all())
            ->searchable()
            ->preload()
            ->dehydrated(false)
            ->live()
            ->afterStateUpdated(function ($state, callable $set, callable $get) use ($field, $idKey, $listField, $modelClass, $typeKey): void {
                if (blank($state)) {
                    return;
                }

                $model = self::modelQuery($modelClass)->find($state);

                if (! $model instanceof Model) {
                    $set($field, null);

                    return;
                }

                $set($listField, self::addModels(
                    models: [$model],
                    items: $get($listField) ?? [],
                    typeKey: $typeKey,
                    idKey: $idKey,
                ));
                $set($field, null);
            });
    }

    /**
     * @param  iterable<Model>  $models
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private static function addModels(iterable $models, array $items, string $typeKey, string $idKey): array
    {
        foreach ($models as $model) {
            $id = $model->getKey();
            $type = $model->getMorphClass();

            if ($id === null || collect($items)->contains(
                fn (array $item): bool => ($item[$typeKey] ?? null) === $type
                    && (string) ($item[$idKey] ?? '') === (string) $id,
            )) {
                continue;
            }

            $items[] = [
                $typeKey => $type,
                $idKey => $id,
                'label' => self::modelLabel($model),
            ];
        }

        return $items;
    }

    /**
     * @template TRelated of EventAttendee|CalendarAudience
     * @template TParent of Event|Calendar
     *
     * @param  HasMany<TRelated, TParent>  $query
     * @param  array<int, mixed>  $state
     * @param  list<class-string<User|Student|Course>>  $allowedModelClasses
     */
    private static function syncMorphRecords(
        HasMany $query,
        array $state,
        string $typeKey,
        string $idKey,
        array $allowedModelClasses,
    ): void {
        $user = self::authenticatedUser();
        $items = collect($state)
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item[$typeKey] ?? null) && filled($item[$idKey] ?? null))
            ->map(fn (array $item): array => [
                $typeKey => (string) $item[$typeKey],
                $idKey => (int) $item[$idKey],
            ])
            ->unique(fn (array $item): string => $item[$typeKey].':'.$item[$idKey])
            ->values();

        if ($items->contains(fn (array $item): bool => ! self::canSelectMorphRecord(
            user: $user,
            type: $item[$typeKey],
            id: $item[$idKey],
            allowedModelClasses: $allowedModelClasses,
        ))) {
            throw ValidationException::withMessages([
                $idKey => 'You may only select people and groups within your access.',
            ]);
        }

        foreach ($query->get() as $record) {
            $recordType = (string) $record->getAttribute($typeKey);
            $recordId = (int) $record->getAttribute($idKey);

            if (! self::canSelectMorphRecord(
                user: $user,
                type: $recordType,
                id: $recordId,
                allowedModelClasses: $allowedModelClasses,
            )) {
                continue;
            }

            if (! $items->contains(
                fn (array $item): bool => $item[$typeKey] === $recordType
                    && $item[$idKey] === $recordId,
            )) {
                $record->delete();
            }
        }

        foreach ($items as $item) {
            $query->updateOrCreate($item);
        }
    }

    /** @param class-string<User|Student> $modelClass */
    private static function modelQuery(string $modelClass): Builder
    {
        return match ($modelClass) {
            User::class => self::userQuery(self::authenticatedUser()),
            Student::class => self::studentQuery(self::authenticatedUser()),
        };
    }

    private static function courseQuery(): Builder
    {
        return self::courseQueryFor(self::authenticatedUser());
    }

    private static function courseQueryFor(User $user): Builder
    {
        return Course::applyActiveTeachingAccessConstraint(Course::query(), $user);
    }

    private static function studentQuery(User $user): Builder
    {
        return Student::applyAdminAccessConstraint(Student::query(), $user);
    }

    private static function userQuery(User $user): Builder
    {
        $query = User::query();

        if (! $user->hasCourseRestrictedAdminAccess()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query
                ->whereHas(
                    'roles',
                    fn (Builder $query): Builder => $query->where('name', Role::OWNER),
                )
                ->orWhereHas(
                    'students.courses',
                    fn (Builder $query): Builder => Course::applyActiveTeachingAccessConstraint($query, $user),
                );
        });
    }

    private static function canSelectModel(?Model $model): bool
    {
        if (! $model instanceof Model) {
            return false;
        }

        $user = self::authenticatedUser();

        return match (true) {
            $model instanceof Course => self::courseQueryFor($user)->whereKey($model->getKey())->exists(),
            $model instanceof Student => self::studentQuery($user)->whereKey($model->getKey())->exists(),
            $model instanceof User => self::userQuery($user)->whereKey($model->getKey())->exists(),
            default => false,
        };
    }

    /**
     * @param  list<class-string<User|Student|Course>>  $allowedModelClasses
     */
    private static function canSelectMorphRecord(
        User $user,
        string $type,
        int $id,
        array $allowedModelClasses,
    ): bool {
        $modelClass = collect($allowedModelClasses)
            ->first(fn (string $modelClass): bool => (new $modelClass)->getMorphClass() === $type);

        if (! is_string($modelClass)) {
            return false;
        }

        return match ($modelClass) {
            User::class => self::userQuery($user)->whereKey($id)->exists(),
            Student::class => self::studentQuery($user)->whereKey($id)->exists(),
            Course::class => self::courseQueryFor($user)->whereKey($id)->exists(),
        };
    }

    private static function authenticatedUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private static function modelLabel(?Model $model): string
    {
        if (! $model instanceof Model) {
            return 'Unknown';
        }

        $label = data_get($model, 'full_name') ?? data_get($model, 'fullName') ?? data_get($model, 'name');

        return is_string($label) ? $label : (string) $model->getKey();
    }

    /**
     * @param  Builder<User|Student>  $query
     * @param  list<int>  $suggestedIds
     * @return array<int, string>
     */
    private static function prioritizedModelOptions(Builder $query, array $suggestedIds): array
    {
        return $query
            ->get()
            ->sortBy(fn (Model $model): array => [
                in_array((int) $model->getKey(), $suggestedIds, true) ? 0 : 1,
                mb_strtolower((string) data_get($model, 'first_name')),
                mb_strtolower((string) data_get($model, 'last_name')),
            ])
            ->mapWithKeys(fn (Model $model): array => [
                (int) $model->getKey() => self::modelLabel($model)
                    .(in_array((int) $model->getKey(), $suggestedIds, true) ? ' — suggested' : ''),
            ])
            ->all();
    }
}
