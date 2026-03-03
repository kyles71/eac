<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Product;
use Carbon\Carbon;

it('calculates available capacity correctly', function () {
    $course = Course::factory()->create(['capacity' => 5]);

    expect($course->availableCapacity())->toBe(5);

    Enrollment::factory(3)->create(['course_id' => $course->id]);

    expect($course->availableCapacity())->toBe(2);
});

it('returns zero when fully enrolled', function () {
    $course = Course::factory()->create(['capacity' => 2]);

    Enrollment::factory(2)->create(['course_id' => $course->id]);

    expect($course->availableCapacity())->toBe(0);
});

it('has a product relationship', function () {
    $course = Course::factory()->create();
    $product = Product::factory()->forCourse($course)->create();

    expect($course->product)->toBeInstanceOf(Product::class)
        ->and($course->product->id)->toBe($product->id);
});

it('scopes available courses', function () {
    $available = Course::factory()->create(['capacity' => 5]);
    $full = Course::factory()->create(['capacity' => 1]);

    Enrollment::factory()->create(['course_id' => $full->id]);

    $results = Course::available()->get();

    expect($results->pluck('id')->toArray())->toContain($available->id)
        ->and($results->pluck('id')->toArray())->not->toContain($full->id);
});

it('returns the soonest future event as nextEvent', function () {
    $course = Course::factory()->create();

    $farFuture = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::now()->addMonth(),
        'end_time' => Carbon::now()->addMonth()->addHour(),
    ]);

    $nearFuture = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::now()->addDay(),
        'end_time' => Carbon::now()->addDay()->addHour(),
    ]);

    // Past event should be ignored
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::now()->subWeek(),
        'end_time' => Carbon::now()->subWeek()->addHour(),
    ]);

    expect($course->nextEvent->id)->toBe($nearFuture->id);
});

it('returns null for nextEvent when no future events exist', function () {
    $course = Course::factory()->create();

    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::now()->subWeek(),
        'end_time' => Carbon::now()->subWeek()->addHour(),
    ]);

    expect($course->nextEvent)->toBeNull();
});

it('returns the most recent past event as previousEvent', function () {
    $course = Course::factory()->create();

    $recentPast = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::now()->subDay(),
        'end_time' => Carbon::now()->subDay()->addHour(),
    ]);

    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::now()->subMonth(),
        'end_time' => Carbon::now()->subMonth()->addHour(),
    ]);

    // Future event should be ignored
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::now()->addWeek(),
        'end_time' => Carbon::now()->addWeek()->addHour(),
    ]);

    expect($course->previousEvent->id)->toBe($recentPast->id);
});
