<?php

declare(strict_types=1);

use App\Actions\Mail\SendEventReminders;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;

it('registers the customizable event reminder with user student and event tokens', function (): void {
    $definition = app(EmailTypeRegistry::class)->get('event-reminder');

    expect($definition->category)->toBe('transactional')
        ->and(array_keys($definition->tokensByKey()))
        ->toContain(
            'user.first_name',
            'student.full_name',
            'event.name',
            'event.starts_at',
            'course.name',
        )
        ->and(array_keys($definition->slotsByMergeTag()))->toBe(['slot.event-details']);
});

it('reminds eligible standalone event attendees at all associated student emails', function (): void {
    Mail::fake();
    $now = CarbonImmutable::parse('2026-06-19 08:00:00', 'America/New_York');
    $this->travelTo($now->utc());
    $startsAt = $now->addWeeks(2)->setTime(18, 0)->utc();
    $calendar = Calendar::factory()->create(['name' => 'Community <Calendar>']);
    $event = Event::factory()->create([
        'name' => 'Summer <Showcase>',
        'course_id' => null,
        'calendar_id' => $calendar->id,
        'start_time' => $startsAt,
        'end_time' => $startsAt->addHour(),
        'created_at' => $startsAt->subWeeks(3),
    ]);

    $account = User::factory()->create([
        'first_name' => 'Jamie',
        'email' => 'guardian@example.com',
    ]);
    $student = Student::factory()->create([
        'user_id' => $account->id,
        'first_name' => 'Alex',
        'last_name' => 'Dancer',
    ]);
    StudentEmail::factory()->create([
        'student_id' => $student->id,
        'email' => 'student@example.com',
    ]);
    EventAttendee::factory()->forStudent($student)->create([
        'event_id' => $event->id,
        'created_at' => $startsAt->subWeeks(2)->subDay(),
    ]);

    $directUser = User::factory()->create(['email' => 'direct@example.com']);
    EventAttendee::factory()->forUser($directUser)->create([
        'event_id' => $event->id,
        'created_at' => $startsAt->subWeeks(2)->subDay(),
    ]);

    $lateUser = User::factory()->create(['email' => 'late@example.com']);
    EventAttendee::factory()->forUser($lateUser)->create([
        'event_id' => $event->id,
        'created_at' => $startsAt->subWeeks(2)->addMinute(),
    ]);

    expect(app(SendEventReminders::class)->handle())->toBe([
        'events_processed' => 1,
        'emails_queued' => 2,
    ])->and($event->refresh()->reminder_processed_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, 2);
    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'event-reminder'
            && $mail->hasTo('guardian@example.com')
            && $mail->hasTo('student@example.com')
            && $mail->usesMailer('transactional')
            && str_contains($rendered->html, 'Summer &lt;Showcase&gt;')
            && ! str_contains($rendered->html, '<Showcase>');
    });
    Mail::assertNotQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->hasTo('late@example.com'));
});

it('sends one course reminder for its first eligible event and does not process it twice', function (): void {
    Mail::fake();
    $now = CarbonImmutable::parse('2026-06-19 08:00:00', 'America/New_York');
    $this->travelTo($now->utc());
    $startsAt = $now->addWeeks(2)->setTime(17, 0)->utc();
    $course = Course::factory()->create(['name' => 'Ballet 2']);
    $firstEvent = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => $startsAt,
        'end_time' => $startsAt->addHour(),
        'created_at' => $startsAt->subWeeks(4),
    ]);
    $laterEvent = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => $startsAt->addWeek(),
        'end_time' => $startsAt->addWeek()->addHour(),
        'created_at' => $startsAt->subWeeks(4),
    ]);
    $user = User::factory()->create(['email' => 'course-parent@example.com']);
    $student = Student::factory()->create(['user_id' => $user->id]);
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $user->id,
        'created_at' => $startsAt->subWeeks(3),
        'updated_at' => $startsAt->subWeeks(3),
    ]);

    expect(app(SendEventReminders::class)->handle())->toBe([
        'events_processed' => 1,
        'emails_queued' => 1,
    ])->and(app(SendEventReminders::class)->handle())->toBe([
        'events_processed' => 0,
        'emails_queued' => 0,
    ]);

    expect($course->refresh()->event_reminder_processed_at)->not->toBeNull()
        ->and($firstEvent->refresh()->reminder_processed_at)->not->toBeNull()
        ->and($laterEvent->refresh()->reminder_processed_at)->toBeNull();
    Mail::assertQueued(ManagedMail::class, 1);
});

it('never reminds for an event created inside the two-week cutoff', function (): void {
    Mail::fake();
    $now = CarbonImmutable::parse('2026-06-19 08:00:00', 'America/New_York');
    $this->travelTo($now->utc());
    $startsAt = $now->addWeeks(2)->setTime(20, 0)->utc();
    $event = Event::factory()->create([
        'course_id' => null,
        'start_time' => $startsAt,
        'end_time' => $startsAt->addHour(),
        'created_at' => $startsAt->subWeeks(2)->addMinute(),
    ]);
    $user = User::factory()->create();
    EventAttendee::factory()->forUser($user)->create([
        'event_id' => $event->id,
        'created_at' => $event->created_at,
    ]);

    expect(app(SendEventReminders::class)->handle())->toBe([
        'events_processed' => 1,
        'emails_queued' => 0,
    ])->and($event->refresh()->reminder_processed_at)->not->toBeNull();

    Mail::assertNothingQueued();
});

it('runs event reminders through the command', function (): void {
    Mail::fake();

    $this->artisan('events:send-reminders')
        ->expectsOutput('Processed 0 event reminder(s) and queued 0 email(s).')
        ->assertSuccessful();
});
