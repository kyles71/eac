<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Services\AcademicTermService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

it('groups fall winter spring and summer into the academic year that begins in fall', function (): void {
    $fall = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2036)->create();
    $winterSpring = AcademicTerm::factory()->forSemester(CourseSemester::WinterSpring, 2037)->create();
    $summer = AcademicTerm::factory()->forSemester(CourseSemester::Summer, 2037)->create();

    expect($fall->academicYear->starts_in_year)->toBe(2036)
        ->and($winterSpring->academic_year_id)->toBe($fall->academic_year_id)
        ->and($summer->academic_year_id)->toBe($fall->academic_year_id)
        ->and($fall->academicYear->display_name)->toBe('2036–37')
        ->and($fall->academicYear->terms)->toHaveCount(3);
});

it('synchronizes complete current and next academic years idempotently', function (): void {
    $service = app(AcademicTermService::class);
    $date = CarbonImmutable::parse('2030-09-15', 'America/New_York');

    expect($service->sync($date))->toBe(6)
        ->and($service->sync($date))->toBe(0)
        ->and(AcademicYear::query()->whereIn('starts_in_year', [2030, 2031])->count())
        ->toBe(2);

    $academicYear = AcademicYear::query()->where('starts_in_year', 2030)->firstOrFail();

    expect($academicYear->terms()
        ->orderBy('starts_on')
        ->get()
        ->map(fn (AcademicTerm $term): string => $term->display_name)
        ->all())
        ->toBe(['Fall 2030', 'Winter-Spring 2031', 'Summer 2031']);
});

it('validates enrollment goals on academic terms', function (): void {
    AcademicTerm::factory()->create([
        'target_enrollments' => 500,
        'stretch_goal_enrollments' => 650,
    ]);

    expect(fn () => AcademicTerm::factory()->create([
        'target_enrollments' => 500,
        'stretch_goal_enrollments' => 499,
    ]))->toThrow(ValidationException::class, 'stretch goal must be at least');
});

it('prevents deleting an academic year that contains terms', function (): void {
    $term = AcademicTerm::factory()->create();

    expect(fn () => $term->academicYear->delete())
        ->toThrow(ValidationException::class, 'cannot be deleted');
});
