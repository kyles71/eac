<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Services\AcademicTermService;
use App\Settings\AcademicTermSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    AcademicTerm::query()->delete();
});

it('generates current and next year terms from recurring defaults idempotently', function (): void {
    CarbonImmutable::setTestNow('2026-08-15 12:00:00');

    $academicTerms = app(AcademicTermService::class);

    expect($academicTerms->sync())->toBe(6)
        ->and($academicTerms->sync())->toBe(0)
        ->and(AcademicTerm::query()->count())->toBe(6);

    $fall = AcademicTerm::query()
        ->where('semester', CourseSemester::Fall)
        ->where('year', 2026)
        ->firstOrFail();

    expect($fall->starts_on->toDateString())->toBe('2026-09-01')
        ->and($fall->ends_on->toDateString())->toBe('2026-12-31')
        ->and($fall->display_name)->toBe('Fall 2026');
});

it('updates only upcoming default-backed terms when recurring settings change', function (): void {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');

    $academicTerms = app(AcademicTermService::class);
    $academicTerms->sync();

    $fall = AcademicTerm::query()
        ->where('semester', CourseSemester::Fall)
        ->where('year', 2026)
        ->firstOrFail();
    $fall->update([
        'starts_on' => '2026-09-10',
        'uses_default_dates' => false,
    ]);

    $settings = app(AcademicTermSettings::class);
    $settings->summer_starts_on = '06-15';
    $settings->fall_starts_on = '09-15';
    $settings->save();

    $academicTerms->sync();

    $summer2027 = AcademicTerm::query()
        ->where('semester', CourseSemester::Summer)
        ->where('year', 2027)
        ->firstOrFail();

    expect($fall->refresh()->starts_on->toDateString())->toBe('2026-09-10')
        ->and($summer2027->starts_on->toDateString())->toBe('2027-06-15')
        ->and($summer2027->ends_on->toDateString())->toBe('2027-09-14');
});

it('selects the current term inclusively in the display timezone', function (): void {
    $term = AcademicTerm::factory()->forSemester(CourseSemester::Summer, 2026)->create();

    expect($term->isCurrent(CarbonImmutable::parse('2026-06-01 04:00:00 UTC')))->toBeTrue()
        ->and($term->isCurrent(CarbonImmutable::parse('2026-09-01 03:59:59 UTC')))->toBeTrue()
        ->and($term->isCurrent(CarbonImmutable::parse('2026-09-01 04:00:00 UTC')))->toBeFalse()
        ->and(AcademicTerm::query()->current(CarbonImmutable::parse('2026-06-01 04:00:00 UTC'))->first()?->is($term))->toBeTrue();
});

it('rejects overlapping or reversed academic term dates', function (): void {
    AcademicTerm::factory()->forSemester(CourseSemester::Summer, 2026)->create();

    expect(fn () => AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2026)->create([
        'starts_on' => '2026-08-31',
    ]))->toThrow(ValidationException::class);

    expect(fn () => AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2027)->create([
        'starts_on' => '2027-12-31',
        'ends_on' => '2027-09-01',
    ]))->toThrow(ValidationException::class);
});

it('does not delete a term that is assigned to a course', function (): void {
    $term = AcademicTerm::factory()->create();
    Course::factory()->for($term, 'academicTerm')->create();

    expect(fn () => $term->delete())->toThrow(ValidationException::class);
});

it('backfills legacy courses from their saved semester and creation year', function (): void {
    $migration = require database_path('migrations/2026_08_27_022740_add_academic_term_id_to_courses_table.php');

    $migration->down();

    $courseId = DB::table('courses')->insertGetId([
        'name' => 'Legacy Course',
        'description' => null,
        'semester' => CourseSemester::Summer->value,
        'capacity' => 10,
        'guest_teacher' => null,
        'event_reminder_processed_at' => null,
        'created_at' => '2025-11-15 12:00:00',
        'updated_at' => '2025-11-15 12:00:00',
    ]);

    $migration->up();

    $course = Course::query()->findOrFail($courseId);

    expect($course->academicTerm->semester)->toBe(CourseSemester::Summer)
        ->and($course->academicTerm->year)->toBe(2025);
});
