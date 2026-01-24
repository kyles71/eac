<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Enums\ScheduleFrequency;
use App\Models\Course;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EventForm
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
                ->hidden(fn () => $course_id !== null)
                ->relationship('course', 'name'),
            Textarea::make('description')
                ->columnSpanFull(),
            TextInput::make('focus'),
            Textarea::make('details')
                ->label('Lesson Plan')
                ->columnSpanFull(),
            DateTimePicker::make('start_time'),
            DateTimePicker::make('end_time'),
            Select::make('calendar_id')
                ->relationship('calendar', 'name', function ($query) {
                    $query->where('id', '>', 1)->orderBy('id', 'asc');
                })
                ->default(2),
            Select::make('repeat_frequency')
                ->live()
                ->visible(fn (string $operation) => $operation === 'create')
                ->enum(ScheduleFrequency::class)
                ->options(ScheduleFrequency::class),
            DatePicker::make('repeat_through')
                ->visible(fn (Get $get): bool => !! $get('repeat_frequency')),
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
                        ->afterStateUpdated(function ($state, callable $set, $get) {
                            self::handleAddModel(User::class, 'full_name', 'add_user', $state, $set, $get);
                        }),
                    Select::make('add_student')
                        ->loadingMessage('Loading students...')
                        ->options(Student::orderBy('first_name')->orderBy('last_name')->get()->pluck('full_name', 'id'))
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, $get) {
                            self::handleAddModel(Student::class, 'full_name', 'add_student', $state, $set, $get);
                        }),
                    Select::make('add_course')
                        ->loadingMessage('Loading courses...')
                        ->options(Course::orderBy('name')->pluck('name', 'id'))
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, $get) {
                            self::handleAddCourse($state, $set, $get, 'add_course');
                        }),
                    Repeater::make('attendees_list')
                        ->grid(3)
                        ->default([])
                        ->relationship('attendees')
                        ->saveRelationshipsUsing(function (Event $record, $state) {
                            EventAttendee::query()
                                ->where('event_id', $record->id)
                                ->whereNot(function ($query) use ($state) {
                                    foreach ($state as $item) {
                                        $query->orWhere(function ($q) use ($item) {
                                            $q->where('attendee_type', $item['attendee_type'])
                                                ->where('attendee_id', $item['attendee_id']);
                                        });
                                    }
                                })
                                ->delete();

                            foreach ($state as $item) {
                                $record->attendees()->updateOrCreate([
                                    'attendee_type' => $item['attendee_type'],
                                    'attendee_id' => $item['attendee_id'],
                                ]);
                            }
                        })
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
                        ->columnSpanFull()
                ]),
        ];
    }

    private static function handleAddModel(string $modelClass, $labelAccessor, string $fieldName, $state, callable $set, callable $get): void
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

            if (is_callable($labelAccessor)) {
                $label = $labelAccessor($model);
            } else {
                $label = $model->{$labelAccessor} ?? (string) $id;
            }

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
}
