<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use App\Enums\CalendarAccess;
use App\Models\Calendar;
use App\Models\CalendarAudience;
use App\Models\Course;
use App\Models\Event;
use App\Models\EventAttendee;
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
            ->visible(fn (Get $get): bool => $courseId === null && blank($get('course_id')));
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
        );
    }

    /** @return array<int, array{attendee_type: string, attendee_id: int, label: string}> */
    public static function eventInvitationState(Event $event): array
    {
        return $event->attendees()
            ->with('attendee')
            ->get()
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
                    ->options(fn (): array => Course::query()
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

                        $course = Course::query()
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
            ->options(fn (): array => $modelClass::query()
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

                $model = $modelClass::query()->find($state);

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
     */
    private static function syncMorphRecords(HasMany $query, array $state, string $typeKey, string $idKey): void
    {
        $items = collect($state)
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item[$typeKey] ?? null) && filled($item[$idKey] ?? null))
            ->map(fn (array $item): array => [
                $typeKey => (string) $item[$typeKey],
                $idKey => (int) $item[$idKey],
            ])
            ->unique(fn (array $item): string => $item[$typeKey].':'.$item[$idKey])
            ->values();

        if ($items->isEmpty()) {
            $query->delete();

            return;
        }

        $query
            ->whereNot(function (Builder $query) use ($idKey, $items, $typeKey): void {
                foreach ($items as $item) {
                    $query->orWhere(function (Builder $query) use ($idKey, $item, $typeKey): void {
                        $query
                            ->where($typeKey, $item[$typeKey])
                            ->where($idKey, $item[$idKey]);
                    });
                }
            })
            ->delete();

        foreach ($items as $item) {
            $query->updateOrCreate($item);
        }
    }

    private static function modelLabel(?Model $model): string
    {
        if (! $model instanceof Model) {
            return 'Unknown';
        }

        $label = data_get($model, 'full_name') ?? data_get($model, 'fullName') ?? data_get($model, 'name');

        return is_string($label) ? $label : (string) $model->getKey();
    }
}
