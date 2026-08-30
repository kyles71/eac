<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Enums\EnrollmentEmailAudience;
use App\Filament\Admin\Resources\Enrollments\Pages\ListEnrollments;
use App\Mail\HandcraftedEmail;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\User;
use App\Services\CurrentEnrollmentEmailRecipientsService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-01 12:00:00');
    AcademicTerm::query()->delete();
    Filament::setCurrentPanel('admin');
    config()->set('mail.mailers.handcrafted.archive_to', '');
});

it('resolves current-term account and student audiences without duplicates', function (): void {
    $currentTerm = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2026)->create();
    $pastTerm = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2025)->create();
    $currentCourse = Course::factory()->for($currentTerm, 'academicTerm')->create();
    $pastCourse = Course::factory()->for($pastTerm, 'academicTerm')->create();

    $family = User::factory()->create(['email' => 'family@example.com']);
    $student = Student::factory()->for($family)->create();
    StudentEmail::factory()->for($student)->create(['email' => 'dancer@example.com']);
    StudentEmail::factory()->for($student)->create(['email' => 'FAMILY@example.com']);

    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $currentCourse->id,
        'user_id' => $family->id,
    ]);
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $currentCourse->id,
        'user_id' => $family->id,
    ]);

    $unassignedUser = User::factory()->create(['email' => 'unassigned@example.com']);
    Enrollment::factory()->create([
        'course_id' => $currentCourse->id,
        'user_id' => $unassignedUser->id,
        'student_id' => null,
    ]);

    Enrollment::factory()->create([
        'course_id' => $pastCourse->id,
        'user_id' => User::factory()->create(['email' => 'past@example.com'])->id,
    ]);

    $recipients = app(CurrentEnrollmentEmailRecipientsService::class);

    expect($recipients->forAudience(EnrollmentEmailAudience::UserAccounts))->toBe([
        'family@example.com',
        'unassigned@example.com',
    ])->and($recipients->forAudience(EnrollmentEmailAudience::StudentEmails))->toBe([
        'family@example.com',
        'dancer@example.com',
    ]);
});

it('includes every enrollment in the current term regardless of course event status', function (): void {
    $currentTerm = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2026)->create();
    $concludedCourse = Course::factory()->for($currentTerm, 'academicTerm')->create();
    $futureCourse = Course::factory()->for($currentTerm, 'academicTerm')->create();

    foreach ([$concludedCourse, $futureCourse] as $index => $course) {
        $user = User::factory()->create(['email' => "family{$index}@example.com"]);
        Enrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $user->id,
        ]);
    }

    expect(app(CurrentEnrollmentEmailRecipientsService::class)->forAudience(
        EnrollmentEmailAudience::UserAccounts,
    ))->toBe(['family0@example.com', 'family1@example.com']);
});

it('shows the action only to owners and super admins and disables it without a current term', function (): void {
    $owner = User::factory()->isOwner()->create();
    $owner->givePermissionTo('ViewAny:Enrollment');
    $this->actingAs($owner);

    livewire(ListEnrollments::class)
        ->assertActionVisible('emailCurrentEnrollees')
        ->assertActionDisabled('emailCurrentEnrollees');

    AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2026)->create();

    livewire(ListEnrollments::class)
        ->assertActionEnabled('emailCurrentEnrollees');

    $superAdmin = User::factory()->isSuperAdmin()->create();
    $superAdmin->givePermissionTo('ViewAny:Enrollment');
    $this->actingAs($superAdmin);

    livewire(ListEnrollments::class)
        ->assertActionVisible('emailCurrentEnrollees')
        ->assertActionEnabled('emailCurrentEnrollees');

    $teacher = User::factory()->isTeacher()->create();
    $teacher->givePermissionTo('ViewAny:Enrollment');
    $this->actingAs($teacher);

    livewire(ListEnrollments::class)
        ->assertActionHidden('emailCurrentEnrollees');
});

it('queues one private handcrafted email per unique selected recipient', function (): void {
    Mail::fake();

    $owner = User::factory()->isOwner()->create();
    $owner->givePermissionTo('ViewAny:Enrollment');
    $this->actingAs($owner);

    $currentTerm = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2026)->create();
    $course = Course::factory()->for($currentTerm, 'academicTerm')->create();
    $family = User::factory()->create(['email' => 'family@example.com']);
    $student = Student::factory()->for($family)->create();
    StudentEmail::factory()->for($student)->create(['email' => 'dancer@example.com']);
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $family->id,
    ]);

    livewire(ListEnrollments::class)
        ->mountAction('emailCurrentEnrollees')
        ->assertActionDataSet([
            'audience' => EnrollmentEmailAudience::UserAccounts,
        ])
        ->setActionData([
            'audience' => EnrollmentEmailAudience::StudentEmails->value,
            'subject' => 'Term update',
            'body' => 'Important news.',
        ])
        ->callMountedAction()
        ->assertNotified('Email queued');

    Mail::assertQueued(HandcraftedEmail::class, 2);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('family@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('dancer@example.com'));
});

it('warns without queueing when the selected audience has no recipients', function (): void {
    Mail::fake();

    $owner = User::factory()->isOwner()->create();
    $owner->givePermissionTo('ViewAny:Enrollment');
    $this->actingAs($owner);

    AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2026)->create();

    livewire(ListEnrollments::class)
        ->mountAction('emailCurrentEnrollees')
        ->setActionData([
            'audience' => EnrollmentEmailAudience::UserAccounts->value,
            'subject' => 'Term update',
            'body' => 'Important news.',
        ])
        ->callMountedAction()
        ->assertNotified('No matching recipients');

    Mail::assertNothingQueued();
});
