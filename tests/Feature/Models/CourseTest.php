<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Product;

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
