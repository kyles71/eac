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

it('shows assignment state and schedule context on the enrollment table', function (): void {
    $course = Course::factory()->create(['name' => 'Ballet 2']);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::now()->addWeek(),
        'end_time' => Carbon::now()->addWeek()->addHour(),
    ]);
    $openEnrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'student_id' => null,
    ]);

    livewire(ListEnrollments::class)
        ->set('activeTab', 'all')
        ->loadTable()
        ->assertCanSeeTableRecords([$openEnrollment])
        ->assertTableColumnExists('course.semester')
        ->assertTableColumnExists('next_class')
        ->assertTableColumnExists('assignment_status')
        ->assertTableColumnStateSet('assignment_status', 'Needs student', $openEnrollment)
        ->assertTableFilterExists('assignment_status')
        ->assertTableFilterExists('course_id');
});
