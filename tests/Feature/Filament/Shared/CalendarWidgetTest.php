<?php

declare(strict_types=1);

use App\Filament\Shared\Widgets\CalendarWidget;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;

it('includes course events for any assigned teacher on my calendar', function () {
    $calendar = Calendar::factory()->create([
        'name' => 'My Calendar',
        'background_color' => '#111111',
    ]);
    $teacher = User::factory()->create();
    $otherTeacher = User::factory()->create();
    $course = Course::factory()->create(['name' => 'Ballet 1']);
    $otherCourse = Course::factory()->create(['name' => 'Ballet 2']);

    $course->teachers()->sync([$teacher->id]);
    $otherCourse->teachers()->sync([$otherTeacher->id]);

    Event::factory()->create([
        'name' => 'Assigned Teacher Class',
        'course_id' => $course->id,
        'calendar_id' => $calendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);
    Event::factory()->create([
        'name' => 'Other Teacher Class',
        'course_id' => $otherCourse->id,
        'calendar_id' => $calendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    $this->actingAs($teacher);

    $widget = new CalendarWidget();
    $widget->mount();

    $events = collect($widget->fetchEvents([
        'start' => '2027-01-01T00:00:00',
        'end' => '2027-01-31T23:59:59',
    ]));

    expect($events->pluck('title')->all())
        ->toContain('Ballet 1 Class')
        ->not->toContain('Ballet 2 Class');
});
