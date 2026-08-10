<?php

declare(strict_types=1);

use App\Actions\Students\SendStudentCommunication;
use App\Enums\StopLightColor;
use App\Enums\StudentCommunicationType;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Student;
use App\Models\StudentCommunication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

it('records and queues an immutable first aid communication to each recipient', function (): void {
    Mail::fake();
    $teacher = User::factory()->isTeacher()->create([
        'first_name' => 'Jamie',
        'last_name' => 'Teacher',
    ]);
    $course = Course::factory()->create(['name' => 'Ballet 2']);
    $course->teachers()->attach($teacher);
    $student = Student::factory()->create([
        'first_name' => 'Alex',
        'last_name' => 'Dancer',
    ]);
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $event = Event::factory()->create([
        'name' => 'Ballet Class',
        'course_id' => $course->id,
        'start_time' => '2026-08-03 18:00:00',
        'end_time' => '2026-08-03 19:00:00',
    ]);

    $occurredAt = CarbonImmutable::parse('2026-08-03 19:30:00', (string) config('app.display_timezone'));
    $communication = app(SendStudentCommunication::class)->handle(
        student: $student,
        author: $teacher,
        type: StudentCommunicationType::FirstAid,
        occurredAt: $occurredAt,
        event: $event,
        stopLightColor: null,
        note: "Applied an ice pack.\nStudent returned to class.",
        recipientEmails: ['family@example.com', 'dancer@example.com'],
    );

    expect($communication)->toBeInstanceOf(StudentCommunication::class)
        ->and($communication->type)->toBe(StudentCommunicationType::FirstAid)
        ->and($communication->stop_light_color)->toBeNull()
        ->and($communication->student->is($student))->toBeTrue()
        ->and($communication->event?->is($event))->toBeTrue()
        ->and($communication->author?->is($teacher))->toBeTrue()
        ->and($communication->occurred_at->toDateTimeString())->toBe($occurredAt->utc()->toDateTimeString())
        ->and($communication->recipient_emails)->toBe(['family@example.com', 'dancer@example.com'])
        ->and($communication->queued_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, 2);
    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->hasTo('family@example.com')
            && $rendered->emailTypeKey === 'student-first-aid-note'
            && str_contains($rendered->subject, 'Alex Dancer')
            && str_contains($rendered->html, 'Applied an ice pack.')
            && str_contains($rendered->html, 'Jamie Teacher')
            && str_contains($rendered->html, 'August 3, 2026 7:30 PM EDT')
            && str_contains($rendered->html, 'Ballet 2 Class');
    });
});

it('records and renders the selected stop light color without an event', function (): void {
    Mail::fake();
    $owner = User::factory()->isOwner()->create();
    $student = Student::factory()->create();

    $communication = app(SendStudentCommunication::class)->handle(
        student: $student,
        author: $owner,
        type: StudentCommunicationType::StopLight,
        occurredAt: '2026-08-04 18:15:00',
        event: null,
        stopLightColor: StopLightColor::Yellow,
        note: 'A yellow stop light message.',
        recipientEmails: ['family@example.com'],
    );

    expect($communication?->stop_light_color)->toBe(StopLightColor::Yellow)
        ->and($communication?->event_id)->toBeNull();

    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->hasTo('family@example.com')
            && $rendered->emailTypeKey === 'student-stop-light-message'
            && str_contains($rendered->subject, 'Yellow stop light')
            && str_contains($rendered->html, 'No event selected');
    });
});

it('requires a color for stop light communications', function (): void {
    $owner = User::factory()->isOwner()->create();

    app(SendStudentCommunication::class)->handle(
        student: Student::factory()->create(),
        author: $owner,
        type: StudentCommunicationType::StopLight,
        occurredAt: now(),
        event: null,
        stopLightColor: null,
        note: 'Missing color.',
        recipientEmails: ['family@example.com'],
    );
})->throws(InvalidArgumentException::class, 'A stop-light color is required.');

it('rejects events unrelated to the student', function (): void {
    $owner = User::factory()->isOwner()->create();

    app(SendStudentCommunication::class)->handle(
        student: Student::factory()->create(),
        author: $owner,
        type: StudentCommunicationType::FirstAid,
        occurredAt: now(),
        event: Event::factory()->create(),
        stopLightColor: null,
        note: 'Unrelated event.',
        recipientEmails: ['family@example.com'],
    );
})->throws(ModelNotFoundException::class);

it('rejects teachers communicating about students they do not teach', function (): void {
    app(SendStudentCommunication::class)->handle(
        student: Student::factory()->create(),
        author: User::factory()->isTeacher()->create(),
        type: StudentCommunicationType::FirstAid,
        occurredAt: now(),
        event: null,
        stopLightColor: null,
        note: 'Unauthorized.',
        recipientEmails: ['family@example.com'],
    );
})->throws(AuthorizationException::class);

it('does not create a record when the managed template is disabled', function (): void {
    Mail::fake();
    app(ManagedTemplateRepository::class)->saveOverride('student-first-aid-note', [
        'is_active' => false,
    ]);
    $owner = User::factory()->isOwner()->create();

    $communication = app(SendStudentCommunication::class)->handle(
        student: Student::factory()->create(),
        author: $owner,
        type: StudentCommunicationType::FirstAid,
        occurredAt: now(),
        event: null,
        stopLightColor: null,
        note: 'Disabled template.',
        recipientEmails: ['family@example.com'],
    );

    expect($communication)->toBeNull()
        ->and(StudentCommunication::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});
