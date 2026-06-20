<?php

declare(strict_types=1);

use App\Enums\HolidayEventScope;
use App\Enums\ScheduleFrequency;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Traits\HasRecurring;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Event;
use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

it('deletes all existing overlapping events by default', function (): void {
    $courseEvent = holidayTestEvent(courseId: Course::factory()->create()->id);
    $standaloneEvent = holidayTestEvent(name: 'Staff Meeting');

    $holiday = Holiday::query()->create([
        'name' => 'Thanksgiving',
        'starts_on' => '2027-11-27',
        'ends_on' => '2027-11-30',
    ]);

    expect($holiday->scope)->toBe(HolidayEventScope::AllEvents)
        ->and($holiday->deletedConflictingEventsCount)->toBe(2)
        ->and(Event::query()->whereKey($courseEvent->id)->exists())->toBeFalse()
        ->and(Event::query()->whereKey($standaloneEvent->id)->exists())->toBeFalse();
});

it('can limit cleanup to course class events', function (): void {
    $courseEvent = holidayTestEvent(courseId: Course::factory()->create()->id);
    $standaloneEvent = holidayTestEvent(name: 'Staff Meeting');

    Holiday::query()->create([
        'name' => 'Class Break',
        'starts_on' => '2027-11-27',
        'ends_on' => '2027-11-30',
        'scope' => HolidayEventScope::CourseClassesOnly,
    ]);

    expect(Event::query()->whereKey($courseEvent->id)->exists())->toBeFalse()
        ->and(Event::query()->whereKey($standaloneEvent->id)->exists())->toBeTrue();
});

it('blocks any positive overlap while allowing exact boundary events', function (): void {
    Holiday::factory()->create([
        'name' => 'Thanksgiving',
        'starts_on' => '2027-11-27',
        'ends_on' => '2027-11-30',
    ]);

    expect(fn (): Event => holidayTestEvent(
        startsAt: localHolidayDateTime('2027-11-26 23:30:00'),
        endsAt: localHolidayDateTime('2027-11-27 00:30:00'),
    ))->toThrow(ValidationException::class, 'Thanksgiving');

    $before = holidayTestEvent(
        startsAt: localHolidayDateTime('2027-11-26 23:00:00'),
        endsAt: localHolidayDateTime('2027-11-27 00:00:00'),
    );
    $after = holidayTestEvent(
        startsAt: localHolidayDateTime('2027-12-01 00:00:00'),
        endsAt: localHolidayDateTime('2027-12-01 01:00:00'),
    );

    expect($before->exists)->toBeTrue()
        ->and($after->exists)->toBeTrue();
});

it('adds a holiday validation error to manually created events', function (): void {
    Filament::setCurrentPanel('admin');

    Holiday::factory()->create([
        'name' => 'Thanksgiving',
        'starts_on' => '2027-11-27',
        'ends_on' => '2027-11-30',
    ]);
    $calendar = Calendar::factory()->create();

    livewire(ListEvents::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Holiday Conflict',
            'start_time' => '2027-11-28 10:00:00',
            'end_time' => '2027-11-28 11:00:00',
            'calendar_id' => $calendar->id,
        ])
        ->assertHasActionErrors(['start_time']);
});

it('skips recurring occurrences that overlap holidays', function (): void {
    Holiday::factory()->create([
        'name' => 'Winter Break',
        'starts_on' => '2027-01-08',
        'ends_on' => '2027-01-08',
    ]);

    $recurring = holidayRecurringHarness();
    $data = $recurring->prepRecurringData([
        'name' => 'Weekly Meeting',
        'start_time' => '2027-01-01 15:00:00',
        'end_time' => '2027-01-01 16:00:00',
        'course_id' => null,
        'repeat_frequency' => ScheduleFrequency::Weekly,
        'repeat_through' => '2027-01-15',
    ]);

    $created = $recurring->createRecurring(
        $data,
        $recurring->repeatThrough(),
        $recurring->repeatFrequency(),
        fn (array $data): Event => Event::query()->create($data),
    );

    expect($created)->toHaveCount(1)
        ->and($created[0]->start_time->toDateTimeString())->toBe('2027-01-15 15:00:00');
});

it('renders holidays as non-interactive all-day entries on every accessible calendar', function (): void {
    Filament::setCurrentPanel('user');

    $user = User::factory()->create();
    $this->actingAs($user);

    $calendars = Calendar::factory(2)->create();
    $calendars->each(fn (Calendar $calendar) => $calendar->attachTag(
        Calendar::AUDIENCE_TAG_PUBLIC,
        Calendar::AUDIENCE_TAG_TYPE,
    ));
    $holiday = Holiday::factory()->create([
        'name' => 'Thanksgiving',
        'starts_on' => '2027-11-27',
        'ends_on' => '2027-11-30',
    ]);

    foreach ($calendars as $calendar) {
        $widget = new CalendarWidget();
        $widget->selectedCalendarId = $calendar->id;
        $widget->mount();

        $calendarHoliday = collect($widget->fetchEvents([
            'start' => '2027-11-01T00:00:00',
            'end' => '2027-12-01T00:00:00',
        ]))->firstWhere('id', "holiday-{$holiday->id}");

        expect($calendarHoliday)
            ->title->toBe('Thanksgiving')
            ->start->toBe('2027-11-27')
            ->end->toBe('2027-12-01')
            ->allDay->toBeTrue()
            ->editable->toBeFalse()
            ->extendedProps->toBe(['isHoliday' => true, 'isCancelled' => false]);
    }
});

function holidayTestEvent(
    string $name = 'Course Class',
    ?int $courseId = null,
    ?Carbon $startsAt = null,
    ?Carbon $endsAt = null,
): Event {
    return Event::factory()->create([
        'name' => $name,
        'course_id' => $courseId,
        'start_time' => $startsAt ?? localHolidayDateTime('2027-11-28 10:00:00'),
        'end_time' => $endsAt ?? localHolidayDateTime('2027-11-28 11:00:00'),
    ]);
}

function localHolidayDateTime(string $dateTime): Carbon
{
    return Carbon::parse($dateTime, config('app.display_timezone'))
        ->timezone(config('app.timezone'));
}

function holidayRecurringHarness(): object
{
    return new class()
    {
        use HasRecurring;

        public function repeatThrough(): ?Carbon
        {
            return $this->repeat_through;
        }

        public function repeatFrequency(): ?ScheduleFrequency
        {
            return $this->repeat_frequency;
        }
    };
}
