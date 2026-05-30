<?php

declare(strict_types=1);

use App\Filament\User\Pages\MyEnrollments;
use App\Models\Course;
use App\Models\Enrollment;
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
        ->callAction(TestAction::make('assignStudent')->table($enrollment), data: [
            'student_id' => $student->id,
        ])
        ->assertNotified('Enrollment updated');

    expect($enrollment->refresh()->student_id)->toBe($student->id);
});

it('can remove a student from an enrollment beyond the configured cutoff', function () {
    config(['app.enrollment_unassign_cutoff_days' => 7]);

    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $course = Course::factory()->create(['start_time' => now()->addDays(8)]);
    $enrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $course->id,
            'user_id' => auth()->id(),
            'student_id' => $student->id,
        ]);

    livewire(MyEnrollments::class)
        ->set('activeTab', 'future')
        ->callAction(TestAction::make('removeStudent')->table($enrollment))
        ->assertNotified('Student removed from enrollment');

    expect($enrollment->refresh()->student_id)->toBeNull();
});

it('does not allow removing a student inside the configured cutoff', function () {
    config(['app.enrollment_unassign_cutoff_days' => 7]);

    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $course = Course::factory()->create(['start_time' => now()->addDays(6)]);
    $enrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $course->id,
            'user_id' => auth()->id(),
            'student_id' => $student->id,
        ]);

    livewire(MyEnrollments::class)
        ->set('activeTab', 'future')
        ->assertActionHidden(TestAction::make('removeStudent')->table($enrollment));
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
