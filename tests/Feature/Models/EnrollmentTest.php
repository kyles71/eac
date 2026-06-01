<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use Carbon\Carbon;

it('returns enrollments without a student using the open scope', function () {
    $open = Enrollment::factory()->create(['student_id' => null]);
    $assigned = Enrollment::factory()->withStudent()->create();

    $results = Enrollment::open()->pluck('id');

    expect($results)->toContain($open->id)
        ->and($results)->not->toContain($assigned->id);
});

it('returns active enrollments with a past course start and future events', function () {
    $course = Course::factory()->create([
        'start_time' => Carbon::now()->subWeek(),
    ]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::now()->addDay(),
        'end_time' => Carbon::now()->addDay()->addHour(),
    ]);

    $active = Enrollment::factory()->withStudent()->create(['course_id' => $course->id]);

    // Enrollment without a student should not appear (open, not active)
    $open = Enrollment::factory()->create(['course_id' => $course->id, 'student_id' => null]);

    $results = Enrollment::active()->pluck('enrollments.id');

    expect($results)->toContain($active->id)
        ->and($results)->not->toContain($open->id);
});

it('excludes enrollments from courses that have no future events from active scope', function () {
    $pastCourse = Course::factory()->create([
        'start_time' => Carbon::now()->subMonth(),
    ]);
    Event::factory()->create([
        'course_id' => $pastCourse->id,
        'start_time' => Carbon::now()->subWeek(),
        'end_time' => Carbon::now()->subWeek()->addHour(),
    ]);

    $enrollment = Enrollment::factory()->withStudent()->create(['course_id' => $pastCourse->id]);

    $results = Enrollment::active()->pluck('enrollments.id');

    expect($results)->not->toContain($enrollment->id);
});

it('returns future enrollments where the course has not started yet', function () {
    $futureCourse = Course::factory()->create([
        'start_time' => Carbon::now()->addMonth(),
    ]);

    $future = Enrollment::factory()->withStudent()->create(['course_id' => $futureCourse->id]);

    // Enrollment for a course already started should not appear
    $startedCourse = Course::factory()->create([
        'start_time' => Carbon::now()->subWeek(),
    ]);
    $started = Enrollment::factory()->withStudent()->create(['course_id' => $startedCourse->id]);

    $results = Enrollment::future()->pluck('id');

    expect($results)->toContain($future->id)
        ->and($results)->not->toContain($started->id);
});

it('returns past enrollments where the course started and has no future events', function () {
    $pastCourse = Course::factory()->create([
        'start_time' => Carbon::now()->subMonth(),
    ]);
    // Only past events
    Event::factory()->create([
        'course_id' => $pastCourse->id,
        'start_time' => Carbon::now()->subWeek(),
        'end_time' => Carbon::now()->subWeek()->addHour(),
    ]);

    $past = Enrollment::factory()->withStudent()->create(['course_id' => $pastCourse->id]);

    // Active course (has future events) should NOT appear in past
    $activeCourse = Course::factory()->create([
        'start_time' => Carbon::now()->subWeek(),
    ]);
    Event::factory()->create([
        'course_id' => $activeCourse->id,
        'start_time' => Carbon::now()->addDay(),
        'end_time' => Carbon::now()->addDay()->addHour(),
    ]);
    $active = Enrollment::factory()->withStudent()->create(['course_id' => $activeCourse->id]);

    $results = Enrollment::past()->pluck('enrollments.id');

    expect($results)->toContain($past->id)
        ->and($results)->not->toContain($active->id);
});

it('returns semester enrollments until the course has concluded', function () {
    $currentCourse = Course::factory()->create([
        'semester' => CourseSemester::Summer,
        'start_time' => Carbon::now()->subWeek(),
    ]);
    Event::factory()->create([
        'course_id' => $currentCourse->id,
        'start_time' => Carbon::now()->addDay(),
        'end_time' => Carbon::now()->addDay()->addHour(),
    ]);
    $currentEnrollment = Enrollment::factory()->create(['course_id' => $currentCourse->id]);

    $concludedCourse = Course::factory()->create([
        'semester' => CourseSemester::Summer,
        'start_time' => Carbon::now()->subMonth(),
    ]);
    Event::factory()->create([
        'course_id' => $concludedCourse->id,
        'start_time' => Carbon::now()->subWeek(),
        'end_time' => Carbon::now()->subWeek()->addHour(),
    ]);
    $concludedEnrollment = Enrollment::factory()->create(['course_id' => $concludedCourse->id]);

    $fallEnrollment = Enrollment::factory()->create([
        'course_id' => Course::factory()->create([
            'semester' => CourseSemester::Fall,
            'start_time' => Carbon::now()->addMonth(),
        ])->id,
    ]);

    $results = Enrollment::semester(CourseSemester::Summer)->pluck('id');

    expect($results)->toContain($currentEnrollment->id)
        ->and($results)->not->toContain($concludedEnrollment->id)
        ->and($results)->not->toContain($fallEnrollment->id);
});
