<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Schemas;

use App\Enums\ScheduleFrequency;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class EventForm
{
    public static function configure(Schema $schema, $course_id = null): Schema
    {
        return $schema
            ->components(self::components($course_id));
    }

    public static function components($course_id = null): array
    {
        return [
            TextInput::make('name')
                ->required(),
            Select::make('course_id')
                ->hidden(fn (): bool => $course_id !== null)
                ->relationship('course', 'name'),
            Textarea::make('description')
                ->columnSpanFull(),
            TextInput::make('focus'),
            Textarea::make('details')
                ->label('Lesson Plan')
                ->columnSpanFull(),
            DateTimePicker::make('start_time')
                ->timezone(self::displayTimezone()),
            DateTimePicker::make('end_time')
                ->timezone(self::displayTimezone()),
            Select::make('calendar_id')
                ->relationship('calendar', 'name', function ($query): void {
                    $user = auth()->user();

                    $query
                        ->where('slug', '!=', Calendar::SLUG_MY)
                        ->when($user instanceof User, fn ($query) => $query->assignableBy($user))
                        ->orderBy('id', 'asc');
                })
                ->live()
                ->default(fn (): ?int => Calendar::query()
                    ->where('slug', Calendar::SLUG_EAC)
                    ->value('id')),
            Select::make('excluded_user_ids')
                ->label('Excluded Users')
                ->multiple()
                ->preload()
                ->searchable()
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

                    $userIds = User::query()
                        ->whereHas('roles')
                        ->whereIn('id', $state)
                        ->pluck('id')
                        ->all();

                    $record->excludedUsers()->sync($userIds);
                })
                ->dehydrated(false)
                ->columnSpanFull(),
            Section::make('Media')
                ->columns(2)
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
                ]),
            Select::make('repeat_frequency')
                ->live()
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->enum(ScheduleFrequency::class)
                ->options(ScheduleFrequency::class),
            DatePicker::make('repeat_through')
                ->visible(fn (Get $get): bool => (bool) $get('repeat_frequency')),
            Fieldset::make('Attendees')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Text::make('Add attendees to the event from...')
                        ->columnSpanFull(),
                    Select::make('add_user')
                        ->loadingMessage('Loading users...')
                        ->options(User::orderBy('first_name')->orderBy('last_name')->get()->pluck('full_name', 'id'))
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                            self::handleAddModel(User::class, 'full_name', 'add_user', $state, $set, $get);
                        }),
                    Select::make('add_student')
                        ->loadingMessage('Loading students...')
                        ->options(Student::orderBy('first_name')->orderBy('last_name')->get()->pluck('full_name', 'id'))
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                            self::handleAddModel(Student::class, 'full_name', 'add_student', $state, $set, $get);
                        }),
                    Select::make('add_course')
                        ->loadingMessage('Loading courses...')
                        ->options(Course::orderBy('name')->pluck('name', 'id'))
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                            self::handleAddCourse($state, $set, $get, 'add_course');
                        }),
                    Repeater::make('attendees_list')
                        ->grid(3)
                        ->default([])
                        ->loadStateFromRelationshipsUsing(function (Repeater $component, Event $record): void {
                            $component->state(self::attendeeState($record));
                        })
                        ->saveRelationshipsUsing(function (Event $record, ?array $state): void {
                            self::syncAttendees($record, $state ?? []);
                        })
                        ->dehydrated(false)
                        ->schema([
                            TextInput::make('label'),
                            TextInput::make('attendee_type'),
                            TextInput::make('attendee_id'),
                        ])
                        ->itemLabel(fn (array $state) => $state['label'] ?? 'Unknown Attendee')
                        ->collapsed()
                        ->collapsible(false)
                        ->reorderable(false)
                        ->addable(false)
                        ->columnSpanFull(),
                ]),
        ];
    }

    private static function handleAddModel(string $modelClass, string $labelAccessor, string $fieldName, $state, callable $set, callable $get): void
    {
        if (! $state) {
            return;
        }

        $model = $modelClass::find($state);
        if (! $model) {
            $set($fieldName, null);

            return;
        }

        $attendees = $get('attendees_list') ?? [];

        // reuse the bulk adder for a single model
        $attendees = self::addModelsToAttendees([$model], $modelClass, $labelAccessor, $attendees);

        // persist and clear trigger field
        self::finalizeAttendeesChange($set, $fieldName, $attendees);
    }

    private static function excludedUserOptions(int $calendarId): array
    {
        $calendar = Calendar::query()
            ->with('tags')
            ->find($calendarId);

        $query = User::query()
            ->whereHas('roles')
            ->orderBy('first_name')
            ->orderBy('last_name');

        if ($calendar instanceof Calendar && ! $calendar->isPublicSystemCalendar()) {
            $audienceTagIds = $calendar->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)
                ->pluck('id');

            if ($calendar->isInternalSystemCalendar() || $calendar->isAudienceSystemCalendar() || $audienceTagIds->isNotEmpty()) {
                if ($audienceTagIds->isEmpty()) {
                    return [];
                }

                $query->whereHas('tags', fn (Builder $query): Builder => $query
                    ->where('type', Calendar::AUDIENCE_TAG_TYPE)
                    ->whereIn('tags.id', $audienceTagIds));
            }
        }

        return $query
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->fullName])
            ->all();
    }

    /**
     * @return array<int, array{attendee_type: string|null, attendee_id: int|null, label: string}>
     */
    private static function attendeeState(Event $event): array
    {
        return $event
            ->attendees()
            ->with('attendee')
            ->get()
            ->map(fn (EventAttendee $eventAttendee): array => [
                'attendee_type' => $eventAttendee->attendee_type,
                'attendee_id' => $eventAttendee->attendee_id,
                'label' => self::attendeeLabel($eventAttendee->attendee),
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     */
    private static function syncAttendees(Event $event, array $state): void
    {
        $attendees = collect($state)
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item['attendee_type'] ?? null) && filled($item['attendee_id'] ?? null))
            ->map(fn (array $item): array => [
                'attendee_type' => (string) $item['attendee_type'],
                'attendee_id' => (int) $item['attendee_id'],
            ])
            ->unique(fn (array $item): string => $item['attendee_type'].':'.$item['attendee_id'])
            ->values();

        if ($attendees->isEmpty()) {
            $event->attendees()->delete();

            return;
        }

        $event->attendees()
            ->whereNot(function (Builder $query) use ($attendees): void {
                foreach ($attendees as $attendee) {
                    $query->orWhere(function (Builder $query) use ($attendee): void {
                        $query
                            ->where('attendee_type', $attendee['attendee_type'])
                            ->where('attendee_id', $attendee['attendee_id']);
                    });
                }
            })
            ->delete();

        foreach ($attendees as $attendee) {
            $event->attendees()->updateOrCreate($attendee);
        }
    }

    private static function attendeeLabel(?Model $attendee): string
    {
        if (! $attendee instanceof Model) {
            return 'Unknown Attendee';
        }

        $label = data_get($attendee, 'full_name') ?? data_get($attendee, 'name');

        return is_string($label) ? $label : (string) $attendee->getKey();
    }

    private static function handleAddCourse($state, callable $set, callable $get, string $fieldName = 'add_course'): void
    {
        if (! $state) {
            return;
        }

        $course = Course::with('students')->find($state);
        if (! $course) {
            $set($fieldName, null);

            return;
        }

        $attendees = $get('attendees_list') ?? [];

        // add all students from the course using the shared helper
        $attendees = self::addModelsToAttendees($course->students, Student::class, 'full_name', $attendees);

        self::finalizeAttendeesChange($set, $fieldName, $attendees);
    }

    /**
     * Add one or more Eloquent model instances to the attendees array if missing.
     *
     * @param  iterable  $models  Iterable of Eloquent model instances
     * @param  class-string  $modelClass
     * @param  string|callable  $labelAccessor
     * @param  array<int, array<string, mixed>>  $attendees  (by-ref) current attendees array
     * @return array<int, array<string, mixed>> Updated attendees array
     */
    private static function addModelsToAttendees(iterable $models, string $modelClass, $labelAccessor, array $attendees): array
    {
        foreach ($models as $model) {
            // determine id and label from the model instance
            $id = $model->id ?? null;
            if ($id === null) {
                continue;
            }

            $label = is_callable($labelAccessor) ? $labelAccessor($model) : $model->{$labelAccessor} ?? (string) $id;

            foreach ($attendees as $existing) {
                if (($existing['attendee_type'] ?? null) === $modelClass && ((string) ($existing['attendee_id'] ?? '') === (string) $id)) {
                    continue 2; // skip to next model
                }
            }

            $attendees[] = [
                'attendee_type' => $modelClass,
                'attendee_id' => $id,
                'label' => $label,
            ];
        }

        return $attendees;
    }

    /**
     * Persist attendees_list and clear the triggering field.
     */
    private static function finalizeAttendeesChange(callable $set, string $fieldName, array $attendees): void
    {
        $set('attendees_list', $attendees);
        $set($fieldName, null);
    }

    private static function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }
}
