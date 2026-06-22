<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Schemas;

use App\Enums\ScheduleFrequency;
use App\Filament\Shared\Schemas\PeopleAndGroupsPicker;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Services\HolidayConflictService;
use App\Support\MediaDisks;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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
                        ->required()
                        ->maxLength(255),
                    Select::make('course_id')
                        ->label('Course')
                        ->hidden(fn (): bool => $course_id !== null)
                        ->relationship('course', 'name')
                        ->searchable()
                        ->preload()
                        ->live(),
                    TextInput::make('focus')
                        ->label('Focus / Theme'),
                    Textarea::make('description')
                        ->label('Public Description')
                        ->columnSpanFull(),
                    Textarea::make('details')
                        ->label('Lesson Plan')
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
                                    $value,
                                    $get('end_time'),
                                    $get('course_id'),
                                );

                                if ($holiday !== null) {
                                    $fail("This event overlaps the \"{$holiday->name}\" holiday.");
                                }
                            },
                        ])
                        ->timezone(self::displayTimezone()),
                    DateTimePicker::make('end_time')
                        ->label('Ends At')
                        ->required()
                        ->afterOrEqual('start_time')
                        ->timezone(self::displayTimezone()),
                    Select::make('repeat_frequency')
                        ->label('Repeat')
                        ->placeholder('Does not repeat')
                        ->live()
                        ->visible(fn (string $operation): bool => $operation === 'create')
                        ->enum(ScheduleFrequency::class)
                        ->options(ScheduleFrequency::class),
                    DatePicker::make('repeat_through')
                        ->label('Repeat Through')
                        ->required(fn (Get $get): bool => filled($get('repeat_frequency')))
                        ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && filled($get('repeat_frequency'))),
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
                ->schema([
                    SpatieMediaLibraryFileUpload::make('images')
                        ->label('Images')
                        ->collection('images')
                        ->disk(MediaDisks::public())
                        ->visibility('public')
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

    private static function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }
}
