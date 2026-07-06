<?php

declare(strict_types=1);

use App\Enums\FormPurpose;
use App\Enums\FormResponseStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\FormResponse;
use App\Models\FormVersion;
use App\Models\Student;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

function createPublishedRequiredForm(array $attributes = []): Form
{
    $form = Form::factory()->create([
        'purpose' => FormPurpose::MedicalWaiver,
        ...$attributes,
    ]);

    FormVersion::factory()
        ->for($form)
        ->published()
        ->create(['valid_until' => null]);

    return $form;
}

it('assigns required forms when a student is assigned to an enrollment', function (): void {
    $form = createPublishedRequiredForm();
    $course = Course::factory()->create();
    $course->forms()->attach($form);
    $student = Student::factory()->create();

    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);

    $assignment = FormAssignment::query()
        ->where('form_id', $form->id)
        ->whereMorphedTo('subject', $student)
        ->firstOrFail();

    expect($assignment->respondent_id)->toBe($student->user_id)
        ->and($assignment->respondent_type)->toBe($student->user->getMorphClass())
        ->and($assignment->form_version_id)->toBe($form->currentVersion->id);
});

it('backfills new course requirements and removes stale pending assignments', function (): void {
    $form = createPublishedRequiredForm();
    $course = Course::factory()->create();
    $student = Student::factory()->create();

    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);

    $course->forms()->attach($form);

    $assignment = FormAssignment::query()
        ->where('form_id', $form->id)
        ->whereMorphedTo('subject', $student)
        ->firstOrFail();

    $course->forms()->detach($form);

    assertDatabaseMissing(FormAssignment::class, ['id' => $assignment->id]);
});

it('preserves completed forms that are no longer required', function (): void {
    $form = createPublishedRequiredForm();
    $course = Course::factory()->create();
    $student = Student::factory()->create();

    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $course->forms()->attach($form);

    $assignment = FormAssignment::query()
        ->where('form_id', $form->id)
        ->whereMorphedTo('subject', $student)
        ->firstOrFail();
    FormResponse::factory()->create([
        'form_assignment_id' => $assignment->id,
        'form_version_id' => $assignment->form_version_id,
        'status' => FormResponseStatus::Submitted,
        'signature' => 'Parent Name',
        'date_signed' => today(),
        'submitted_at' => now(),
    ]);

    $course->forms()->detach($form);

    assertDatabaseHas(FormAssignment::class, ['id' => $assignment->id]);
});

it('keeps a pending assignment while another enrolled course still requires it', function (): void {
    $form = createPublishedRequiredForm();
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

    expect(FormAssignment::query()
        ->where('form_id', $form->id)
        ->whereMorphedTo('subject', $student)
        ->count())->toBe(1);
});

it('removes pending assignments when a student no longer belongs to a user', function (): void {
    $form = createPublishedRequiredForm();
    $course = Course::factory()->create();
    $course->forms()->attach($form);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);

    $student->update(['user_id' => null]);

    expect(FormAssignment::query()
        ->where('form_id', $form->id)
        ->whereMorphedTo('subject', $student)
        ->exists())->toBeFalse();
});
