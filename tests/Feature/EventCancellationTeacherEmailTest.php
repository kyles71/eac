<?php

declare(strict_types=1);

use App\Actions\Events\CancelEvent;
use App\Actions\Events\ManageEventTeacherAssignments;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;

it('emails every assigned teacher when an event cancellation email is requested', function (): void {
    Mail::fake();
    $actor = auth()->user();
    $event = Event::factory()->standalone()->create([
        'name' => 'Private Lesson (MAIN CAMPUS)',
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addHour(),
    ]);
    $attendee = User::factory()->create(['email' => 'attendee@example.com']);
    $firstTeacher = User::factory()->isTeacher()->create(['email' => 'first-teacher@example.com']);
    $secondTeacher = User::factory()->isTeacher()->create(['email' => 'second-teacher@example.com']);
    EventAttendee::factory()->forUser($attendee)->create(['event_id' => $event->id]);
    app(ManageEventTeacherAssignments::class)->assignCustom($event, [
        $firstTeacher->id,
        $secondTeacher->id,
    ]);

    expect($actor)->toBeInstanceOf(User::class)
        ->and(app(CancelEvent::class)->handle(
            $event,
            $actor,
            'The teacher is unavailable.',
            true,
        ))->toBe(3);

    foreach (['attendee@example.com', 'first-teacher@example.com', 'second-teacher@example.com'] as $recipient) {
        Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'event-cancellation'
            && $mail->hasTo($recipient));
    }

    Mail::assertQueued(ManagedMail::class, 3);
});
