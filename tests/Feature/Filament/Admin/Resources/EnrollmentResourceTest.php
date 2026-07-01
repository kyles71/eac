<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Enrollments\Pages\ListEnrollments;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Carbon::setTestNow('2026-06-27 11:47:04');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('lists every assigned enrollment for the same active course', function (): void {
    $activeCourse = Course::factory()->create();

    Event::factory()->create([
        'course_id' => $activeCourse->id,
        'start_time' => Carbon::now()->subWeek(),
        'end_time' => Carbon::now()->subWeek()->addHour(),
    ]);
    Event::factory()->create([
        'course_id' => $activeCourse->id,
        'start_time' => Carbon::now()->addDay(),
        'end_time' => Carbon::now()->addDay()->addHour(),
    ]);

    $firstEnrollment = Enrollment::factory()->withStudent()->create([
        'course_id' => $activeCourse->id,
    ]);
    $secondEnrollment = Enrollment::factory()->withStudent()->create([
        'course_id' => $activeCourse->id,
    ]);
    $openEnrollment = Enrollment::factory()->create([
        'course_id' => $activeCourse->id,
        'student_id' => null,
    ]);

    $futureCourse = Course::factory()->create();
    Event::factory()->create([
        'course_id' => $futureCourse->id,
        'start_time' => Carbon::now()->addWeek(),
        'end_time' => Carbon::now()->addWeek()->addHour(),
    ]);
    $futureEnrollment = Enrollment::factory()->withStudent()->create([
        'course_id' => $futureCourse->id,
    ]);

    $concludedCourse = Course::factory()->create();
    Event::factory()->create([
        'course_id' => $concludedCourse->id,
        'start_time' => Carbon::now()->subWeek(),
        'end_time' => Carbon::now()->subWeek()->addHour(),
    ]);
    $pastEnrollment = Enrollment::factory()->withStudent()->create([
        'course_id' => $concludedCourse->id,
    ]);

    livewire(ListEnrollments::class)
        ->set('activeTab', 'active')
        ->loadTable()
        ->assertCanSeeTableRecords([$firstEnrollment, $secondEnrollment])
        ->assertCanNotSeeTableRecords([$openEnrollment, $futureEnrollment, $pastEnrollment]);
});
