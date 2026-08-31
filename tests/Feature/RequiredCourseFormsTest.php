<?php

declare(strict_types=1);

use App\Actions\Forms\AssignFormManually;
use App\Jobs\ReconcileRequiredFormsForCourses;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\FormResponse;
use App\Models\FormVersion;
use App\Models\Student;
use App\Models\User;
use App\Observers\EventObserver;
use App\Services\HolidayConflictService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Kyle\FilamentFormBuilder\Enums\FormResponseStatus;
use Tests\Support\RequiredFormsRecordingDispatcher;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

function createPublishedRequiredForm(array $attributes = []): Form
{
    $form = Form::factory()->create([
        'key' => 'student-waiver',
        ...$attributes,
    ]);

    FormVersion::factory()
        ->for($form)
        ->published()
        ->create();

    return $form->refresh();
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

it('preserves pending manual assignments when they are no longer course requirements', function (): void {
    $form = createPublishedRequiredForm();
    $course = Course::factory()->create();
    $course->forms()->attach($form);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);

    $assignment = app(AssignFormManually::class)->handle(
        $form,
        $student,
        null,
        auth()->user(),
    );

    $course->forms()->detach($form);

    expect($assignment->refresh()->is_manually_assigned)->toBeTrue()
        ->and($assignment->manually_assigned_by_id)->toBe(auth()->id())
        ->and($assignment->manually_assigned_at)->not->toBeNull();
});

it('rejects manual student assignments without a linked user', function (): void {
    $form = createPublishedRequiredForm();
    $student = Student::factory()->create(['user_id' => null]);

    expect(fn () => app(AssignFormManually::class)->handle(
        $form,
        $student,
        null,
        auth()->user(),
    ))->toThrow(LogicException::class, 'linked to a user');
});

it('moves assignment access to the current linked user without changing the historical signer', function (): void {
    $form = createPublishedRequiredForm();
    $previousUser = User::factory()->create();
    $currentUser = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $previousUser->id]);
    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $form->currentVersion->id,
        'respondent_type' => $previousUser->getMorphClass(),
        'respondent_id' => $previousUser->id,
        'subject_type' => $student->getMorphClass(),
        'subject_id' => $student->id,
    ]);
    $response = FormResponse::factory()->create([
        'form_assignment_id' => $assignment->id,
        'form_version_id' => $assignment->form_version_id,
        'status' => FormResponseStatus::Submitted,
        'submitted_by_type' => $previousUser->getMorphClass(),
        'submitted_by_id' => $previousUser->id,
        'submitted_at' => now(),
    ]);

    $student->update(['user_id' => $currentUser->id]);

    expect($assignment->refresh()->respondent_id)->toBe($currentUser->id)
        ->and($response->refresh()->submitted_by_id)->toBe($previousUser->id)
        ->and(Gate::forUser($previousUser)->allows('view', $assignment))->toBeFalse()
        ->and(Gate::forUser($currentUser)->allows('view', $assignment))->toBeTrue();

    $student->update(['user_id' => null]);

    expect(Gate::forUser($currentUser)->allows('view', $assignment->refresh()))->toBeFalse()
        ->and($response->refresh()->submitted_by_id)->toBe($previousUser->id)
        ->and($assignment->exists)->toBeTrue();
});

it('enforces one assignment per form and student', function (): void {
    $form = createPublishedRequiredForm();
    $student = Student::factory()->create();
    $attributes = [
        'form_id' => $form->id,
        'form_version_id' => $form->currentVersion->id,
        'respondent_type' => $student->user->getMorphClass(),
        'respondent_id' => $student->user_id,
        'subject_type' => $student->getMorphClass(),
        'subject_id' => $student->id,
    ];

    FormAssignment::factory()->create($attributes);

    expect(fn () => FormAssignment::factory()->create($attributes))->toThrow(QueryException::class);
});

it('dispatches reconciliation after event changes for every affected course', function (): void {
    $originalCourse = Course::factory()->create();
    $newCourse = Course::factory()->create();
    $event = Event::withoutEvents(fn (): Event => Event::factory()->create(['course_id' => $originalCourse->id]));
    $bus = new RequiredFormsRecordingDispatcher();
    $observer = new EventObserver(app(HolidayConflictService::class), $bus);
    $observer->saved($event);

    Event::withoutEvents(fn (): bool => $event->update(['course_id' => $newCourse->id]));
    $observer->saved($event);

    $observer->deleted($event);

    expect(collect($bus->commands)
        ->map(fn (ReconcileRequiredFormsForCourses $job): array => $job->courseIds)
        ->all())->toBe([
            [$originalCourse->id],
            [$newCourse->id, $originalCourse->id],
            [$newCourse->id],
        ])
        ->and((new ReconcileRequiredFormsForCourses([]))->afterCommit)->toBeTrue();
});

it('reconciles requirements as event windows pass with time', function (): void {
    $form = createPublishedRequiredForm();
    $course = Course::factory()->create();
    $course->forms()->attach($form);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    Event::withoutEvents(fn (): Event => Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addMinutes(15),
        'end_time' => now()->addHour(),
    ]));

    $this->artisan('forms:reconcile-required')->assertSuccessful();

    expect(FormAssignment::query()
        ->where('form_id', $form->id)
        ->whereMorphedTo('subject', $student)
        ->exists())->toBeTrue();

    $this->travel(2)->hours();
    $this->artisan('forms:reconcile-required')->assertSuccessful();

    expect(FormAssignment::query()
        ->where('form_id', $form->id)
        ->whereMorphedTo('subject', $student)
        ->exists())->toBeFalse();
});
