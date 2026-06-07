<?php

declare(strict_types=1);

use App\Enums\FormTypes;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\Student;
use App\Models\StudentWaiver;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

it('assigns required forms when a student is assigned to an enrollment', function (): void {
    $form = Form::factory()->create(['form_type' => FormTypes::StudentWaiver]);
    $course = Course::factory()->create();
    $course->forms()->attach($form);
    $student = Student::factory()->create();

    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);

    $assignment = FormUser::query()
        ->where('form_id', $form->id)
        ->where('student_id', $student->id)
        ->firstOrFail();

    expect($assignment->responseable)->toBeInstanceOf(StudentWaiver::class)
        ->and($assignment->user_id)->toBe($student->user_id);
});

it('backfills new course requirements and removes stale pending assignments', function (): void {
    $form = Form::factory()->create(['form_type' => FormTypes::StudentWaiver]);
    $course = Course::factory()->create();
    $student = Student::factory()->create();

    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);

    $course->forms()->attach($form);

    $assignment = FormUser::query()
        ->where('form_id', $form->id)
        ->where('student_id', $student->id)
        ->firstOrFail();
    $responseableId = $assignment->responseable_id;

    $course->forms()->detach($form);

    assertDatabaseMissing(FormUser::class, ['id' => $assignment->id]);
    assertDatabaseMissing(StudentWaiver::class, ['id' => $responseableId]);
});

it('preserves completed forms that are no longer required', function (): void {
    $form = Form::factory()->create(['form_type' => FormTypes::StudentWaiver]);
    $course = Course::factory()->create();
    $student = Student::factory()->create();

    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $course->forms()->attach($form);

    $assignment = FormUser::query()
        ->where('form_id', $form->id)
        ->where('student_id', $student->id)
        ->firstOrFail();
    $assignment->update([
        'signature' => 'Parent Name',
        'date_signed' => today(),
    ]);

    $course->forms()->detach($form);

    assertDatabaseHas(FormUser::class, ['id' => $assignment->id]);
});

it('keeps a pending assignment while another enrolled course still requires it', function (): void {
    $form = Form::factory()->create(['form_type' => FormTypes::StudentWaiver]);
    $firstCourse = Course::factory()->create();
    $secondCourse = Course::factory()->create();
    $student = Student::factory()->create();

    $firstCourse->forms()->attach($form);
    $secondCourse->forms()->attach($form);
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $firstCourse->id,
        'user_id' => $student->user_id,
    ]);
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $secondCourse->id,
        'user_id' => $student->user_id,
    ]);

    $firstCourse->forms()->detach($form);

    expect(FormUser::query()
        ->where('form_id', $form->id)
        ->where('student_id', $student->id)
        ->count())->toBe(1);
});

it('removes pending assignments when a student no longer belongs to a user', function (): void {
    $form = Form::factory()->create(['form_type' => FormTypes::StudentWaiver]);
    $course = Course::factory()->create();
    $course->forms()->attach($form);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);

    $student->update(['user_id' => null]);

    expect(FormUser::query()
        ->where('form_id', $form->id)
        ->where('student_id', $student->id)
        ->exists())->toBeFalse();
});
