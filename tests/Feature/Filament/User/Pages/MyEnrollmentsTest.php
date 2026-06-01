<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Filament\User\Pages\MyEnrollments;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Student;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
});

it('can render the my classes page', function () {
    livewire(MyEnrollments::class)
        ->assertOk();
});

it('can assign an open purchased enrollment to a student', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $enrollment = Enrollment::factory()->create(['user_id' => auth()->id()]);

    livewire(MyEnrollments::class)
        ->set('activeTab', 'all')
        ->callAction(TestAction::make('assignStudent')->table($enrollment), data: [
            'student_id' => $student->id,
        ])
        ->assertNotified('Enrollment updated');

    expect($enrollment->refresh()->student_id)->toBe($student->id);
});

it('can remove a student from an enrollment beyond the configured cutoff', function () {
    config(['app.enrollment_unassign_cutoff_days' => 7]);

    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $course = Course::factory()->create([
        'semester' => CourseSemester::Fall,
        'start_time' => now()->addDays(8),
    ]);
    $enrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $course->id,
            'user_id' => auth()->id(),
            'student_id' => $student->id,
        ]);

    livewire(MyEnrollments::class)
        ->set('activeTab', CourseSemester::Fall->value)
        ->callAction(TestAction::make('removeStudent')->table($enrollment))
        ->assertNotified('Student removed from enrollment');

    expect($enrollment->refresh()->student_id)->toBeNull();
});

it('does not allow removing a student inside the configured cutoff', function () {
    config(['app.enrollment_unassign_cutoff_days' => 7]);

    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $course = Course::factory()->create([
        'semester' => CourseSemester::Fall,
        'start_time' => now()->addDays(6),
    ]);
    $enrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $course->id,
            'user_id' => auth()->id(),
            'student_id' => $student->id,
        ]);

    livewire(MyEnrollments::class)
        ->set('activeTab', CourseSemester::Fall->value)
        ->assertActionHidden(TestAction::make('removeStudent')->table($enrollment));
});

it('groups current classes by semester and moves concluded classes to past', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);

    $winterCourse = Course::factory()->create([
        'semester' => CourseSemester::WinterSpring,
        'start_time' => now()->subWeek(),
    ]);
    Event::factory()->create([
        'course_id' => $winterCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $winterEnrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $winterCourse->id,
            'user_id' => auth()->id(),
        ]);

    $summerCourse = Course::factory()->create([
        'semester' => CourseSemester::Summer,
        'start_time' => now()->addMonth(),
    ]);
    $summerEnrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $summerCourse->id,
            'user_id' => auth()->id(),
        ]);

    $concludedCourse = Course::factory()->create([
        'semester' => CourseSemester::WinterSpring,
        'start_time' => now()->subMonth(),
    ]);
    Event::factory()->create([
        'course_id' => $concludedCourse->id,
        'start_time' => now()->subWeek(),
        'end_time' => now()->subWeek()->addHour(),
    ]);
    $concludedEnrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $concludedCourse->id,
            'user_id' => auth()->id(),
        ]);

    $winterTabRecords = livewire(MyEnrollments::class)
        ->set('activeTab', CourseSemester::WinterSpring->value)
        ->instance()
        ->getTableRecords()
        ->getCollection()
        ->pluck('id');

    expect($winterTabRecords)->toContain($winterEnrollment->id)
        ->not->toContain($summerEnrollment->id)
        ->not->toContain($concludedEnrollment->id);

    $pastTabRecords = livewire(MyEnrollments::class)
        ->set('activeTab', 'past')
        ->instance()
        ->getTableRecords()
        ->getCollection()
        ->pluck('id');

    expect($pastTabRecords)->toContain($concludedEnrollment->id)
        ->not->toContain($winterEnrollment->id)
        ->not->toContain($summerEnrollment->id);
});

it('does not show assignment actions after a class has concluded', function () {
    $course = Course::factory()->create([
        'semester' => CourseSemester::Fall,
        'start_time' => now()->subMonth(),
    ]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->subWeek(),
        'end_time' => now()->subWeek()->addHour(),
    ]);
    $enrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => auth()->id(),
        'student_id' => null,
    ]);

    livewire(MyEnrollments::class)
        ->set('activeTab', 'past')
        ->assertActionHidden(TestAction::make('assignStudent')->table($enrollment));
});

it('opens course details from a course row without calendar widget actions', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $course = Course::factory()->create([
        'name' => 'Tap Details',
        'description' => 'Bring tap shoes.',
        'semester' => CourseSemester::Fall,
        'start_time' => now()->addWeek(),
        'duration' => 75,
    ]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addHour(),
    ]);
    $enrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $course->id,
            'user_id' => auth()->id(),
        ]);

    livewire(MyEnrollments::class)
        ->set('activeTab', 'all')
        ->mountAction(TestAction::make('viewCourseDetails')->table($enrollment))
        ->assertActionMounted(TestAction::make('viewCourseDetails')->table($enrollment))
        ->assertActionDataSet(fn (array $data): bool => $data['name'] === $course->name
            && $data['semester'] === CourseSemester::Fall->getLabel()
            && $data['student'] === $student->fullName
            && $data['duration'] === '75 minutes'
            && $data['meetings'] === 1
            && $data['status'] === 'Future'
            && ! array_key_exists('tags', $data)
            && $data['description'] === 'Bring tap shoes.')
        ->assertActionDoesNotExist('addCourseProductToCart')
        ->assertActionDoesNotExist('viewCourseProductInStore');
});

it('does not include students from other accounts in assign options', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $otherStudent = Student::factory()->create();
    $component = livewire(MyEnrollments::class);
    $method = new ReflectionMethod(MyEnrollments::class, 'studentOptions');
    $method->setAccessible(true);

    expect($method->invoke($component->instance()))
        ->toHaveKey($student->id)
        ->not->toHaveKey($otherStudent->id);
});
