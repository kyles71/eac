<?php

declare(strict_types=1);

use App\Actions\RecurringPrivateLessons\CreateRecurringPrivateLesson;
use App\Enums\CourseSemester;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\ScheduleFrequency;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Holiday;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;

it('creates a private semester series, skips holidays, and prepares monthly scheduled charges', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    Holiday::factory()->create([
        'starts_on' => '2026-08-17',
        'ends_on' => '2026-08-17',
    ]);
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $teacher = User::factory()->isTeacher()->create();

    $series = app(CreateRecurringPrivateLesson::class)->handle(
        household: $household,
        student: $student,
        teacherIds: [$teacher->id],
        name: 'Ballet Private Lesson',
        description: 'Semester private instruction',
        semester: CourseSemester::Fall,
        lessonPrice: 6000,
        startsAt: CarbonImmutable::parse('2026-08-10 17:00:47', 'America/New_York'),
        durationMinutes: 60,
        repeatThrough: CarbonImmutable::parse('2026-09-07', 'America/New_York'),
        frequency: ScheduleFrequency::Weekly,
    );

    expect($series->course->is_private)->toBeTrue()
        ->and($series->course->capacity)->toBe(1)
        ->and($series->course->teachers)->toHaveCount(1)
        ->and($series->course->events)->toHaveCount(4)
        ->and($series->course->events->pluck('start_time')->map->format('Y-m-d')->all())
        ->not->toContain('2026-08-17')
        ->and($series->course->events->pluck('start_time')->map->format('s')->unique()->all())
        ->toBe(['00'])
        ->and($series->billingPeriods)->toHaveCount(2)
        ->and($series->charges)->toHaveCount(4)
        ->and($series->charges->every(fn ($charge): bool => $charge->status === RecurringPrivateLessonChargeStatus::Scheduled))
        ->toBeTrue()
        ->and($series->charges->every(fn ($charge): bool => ! $charge->product->is_active
            && ! $charge->product->is_store_listed
            && ! $charge->product->allows_payment_plan
            && $charge->product->questions()->doesntExist()))
        ->toBeTrue()
        ->and($series->course->enrollments()->where('student_id', $student->id)->exists())->toBeTrue();
});

it('keeps private lessons off the public calendar and shows them to the household and all staff', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    $eacCalendar = Calendar::query()->where('slug', Calendar::SLUG_EAC)->firstOrFail();
    $myCalendar = Calendar::query()->where('slug', Calendar::SLUG_MY)->firstOrFail();
    $staffCalendar = Calendar::query()->where('slug', Calendar::SLUG_STAFF)->firstOrFail();
    $household = User::factory()->create();
    $unrelatedHousehold = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $teacher = User::factory()->isTeacher()->create();
    $unrelatedTeacher = User::factory()->isTeacher()->create();
    $owner = User::factory()->isOwner()->create();
    $series = app(CreateRecurringPrivateLesson::class)->handle(
        $household,
        $student,
        [$teacher->id],
        'Jazz Private Lesson',
        null,
        CourseSemester::Fall,
        5500,
        CarbonImmutable::parse('2026-08-10 17:00', 'America/New_York'),
        45,
        CarbonImmutable::parse('2026-08-24', 'America/New_York'),
        ScheduleFrequency::Weekly,
    );
    $product = $series->charges->first()->product;

    expect(Product::query()->visibleTo($household)->whereKey($product)->exists())->toBeFalse()
        ->and($product->assignedUsers()->whereKey($household->id)->exists())->toBeTrue()
        ->and($product->canBePurchasedBy($unrelatedTeacher))->toBeFalse()
        ->and($series->course->events->every(fn (Event $event): bool => $event->calendar_id === $staffCalendar->id))->toBeTrue()
        ->and(Event::query()->visibleOnCalendar($eacCalendar, $household)->count())->toBe(0)
        ->and(Event::query()->visibleOnCalendar($eacCalendar, $teacher)->count())->toBe(0)
        ->and(Event::query()->visibleOnCalendar($eacCalendar, $owner)->count())->toBe(0)
        ->and(Event::query()->visibleOnCalendar($myCalendar, $household)->count())->toBe(3)
        ->and(Event::query()->visibleOnCalendar($myCalendar, $unrelatedHousehold)->count())->toBe(0)
        ->and(Event::query()->visibleOnCalendar($staffCalendar, $teacher)->count())->toBe(3)
        ->and(Event::query()->visibleOnCalendar($staffCalendar, $unrelatedTeacher)->count())->toBe(3)
        ->and(Event::query()->visibleOnCalendar($staffCalendar, $owner)->count())->toBe(3)
        ->and($unrelatedTeacher->can('view', $series))->toBeFalse()
        ->and($teacher->can('view', $series))->toBeTrue()
        ->and($teacher->can('update', $series))->toBeFalse()
        ->and($owner->can('update', $series))->toBeTrue();
});

it('rejects a recurring private lesson scheduled within the 24 hour cutoff', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $teacher = User::factory()->isTeacher()->create();

    app(CreateRecurringPrivateLesson::class)->handle(
        $household,
        $student,
        [$teacher->id],
        'Tap Private Lesson',
        null,
        CourseSemester::Fall,
        5000,
        now('America/New_York')->addDay(),
        60,
        now('America/New_York')->addWeeks(3),
        ScheduleFrequency::Weekly,
    );
})->throws(InvalidArgumentException::class, 'more than 24 hours');
