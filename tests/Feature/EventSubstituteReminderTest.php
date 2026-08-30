<?php

declare(strict_types=1);

use App\Actions\Events\ManageEventSubstitution;
use App\Actions\Mail\SendEventSubstituteRequestReminders;
use App\Enums\EventSubstituteRequestStatus;
use App\Models\Event;
use App\Models\EventSubstituteRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;

it('registers all customizable substitute email types', function (): void {
    $registry = app(EmailTypeRegistry::class);

    expect($registry->get('event-substitute-request')->category)->toBe('transactional')
        ->and(array_keys($registry->get('event-substitute-request')->tokensByKey()))
        ->toContain('teacher.full_name', 'requester.full_name', 'event.starts_at', 'request.reason')
        ->and(array_keys($registry->get('event-substitute-request')->slotsByMergeTag()))
        ->toBe(['slot.event-details', 'slot.action'])
        ->and($registry->get('event-substitute-request-reminder')->category)->toBe('transactional')
        ->and($registry->get('event-substitute-removed')->category)->toBe('transactional');
});

it('sends one overdue reminder separately to the teacher and requester after 48 hours', function (): void {
    Mail::fake();
    $event = reminderEvent();
    $teacher = User::factory()->isTeacher()->create(['email' => 'teacher@example.com']);
    $requester = User::factory()->create(['email' => 'requester@example.com']);
    $request = EventSubstituteRequest::factory()->create([
        'event_id' => $event->id,
        'teacher_id' => $teacher->id,
        'requested_by_user_id' => $requester->id,
        'created_at' => now()->subHours(49),
    ]);

    expect(app(SendEventSubstituteRequestReminders::class)->handle())->toBe([
        'expired' => 0,
        'requests_processed' => 1,
        'emails_queued' => 2,
    ])->and($request->refresh()->reminder_processed_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'event-substitute-request-reminder'
        && $mail->hasTo('teacher@example.com'));
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'event-substitute-request-reminder'
        && $mail->hasTo('requester@example.com'));

    expect(app(SendEventSubstituteRequestReminders::class)->handle()['requests_processed'])->toBe(0);
    Mail::assertQueued(ManagedMail::class, 2);
});

it('honors the configurable reminder threshold', function (): void {
    Mail::fake();
    config(['app.substitute_request_reminder_hours' => 12]);
    $request = EventSubstituteRequest::factory()->create([
        'event_id' => reminderEvent()->id,
        'created_at' => now()->subHours(13),
    ]);

    expect(app(SendEventSubstituteRequestReminders::class)->handle()['requests_processed'])->toBe(1)
        ->and($request->refresh()->reminder_processed_at)->not->toBeNull();
});

it('treats a manual resend as the one reminder', function (): void {
    Mail::fake();
    $event = reminderEvent();
    $teacher = User::factory()->isTeacher()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);
    $request = app(ManageEventSubstitution::class)->requestSubstitute($event, $teacher, $actor);
    $request->update(['created_at' => now()->subHours(49)]);
    app(ManageEventSubstitution::class)->resend($request, $actor);

    expect($request->refresh()->reminder_processed_at)->not->toBeNull()
        ->and(app(SendEventSubstituteRequestReminders::class)->handle()['requests_processed'])->toBe(0);
});

it('expires pending requests once their events end', function (): void {
    Mail::fake();
    $request = EventSubstituteRequest::factory()->create([
        'event_id' => Event::factory()->create([
            'course_id' => null,
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHour(),
        ])->id,
        'created_at' => now()->subDays(3),
    ]);

    expect(app(SendEventSubstituteRequestReminders::class)->handle()['expired'])->toBe(1)
        ->and($request->refresh()->status)->toBe(EventSubstituteRequestStatus::Expired)
        ->and($request->closed_at)->not->toBeNull();
    Mail::assertNothingQueued();
});

it('schedules substitute request reminders hourly without overlap', function (): void {
    $event = collect(Schedule::events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'events:send-substitute-request-reminders'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

function reminderEvent(): Event
{
    return Event::factory()->create([
        'course_id' => null,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(3)->addHour(),
    ]);
}
