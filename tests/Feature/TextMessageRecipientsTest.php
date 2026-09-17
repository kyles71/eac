<?php

declare(strict_types=1);

use App\Models\EmergencyContact;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\User;
use App\Services\TextMessages\EventTextRecipients;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

it('deduplicates contacts siblings and multiple event rosters', function (): void {
    $events = Event::factory()->count(2)->create();
    $students = Student::factory()->count(2)->create();

    foreach ($students as $student) {
        foreach ($events as $event) {
            Enrollment::factory()->withStudent($student)->create(['course_id' => $event->course_id]);
        }

        $contact = EmergencyContact::factory()->forStudent($student)->create(['phone_number' => '(313) 555-0123', 'wants_text_updates' => true]);
        EmergencyContact::factory()->for($contact->studentWaiver)->create(['phone_number' => '+13135550123', 'wants_text_updates' => true]);
        EmergencyContact::factory()->for($contact->studentWaiver)->create(['phone_number' => '(313) 555-0999', 'wants_text_updates' => false]);
    }

    $result = app(EventTextRecipients::class)->resolve($events);
    expect(array_keys($result['recipients']))->toBe(['+13135550123'])
        ->and($result['recipients']['+13135550123']['sources'])->toHaveCount(4)
        ->and($result['warnings'])->toBe([]);
});

it('uses standalone student attendees and skips user attendees without inferring their students', function (): void {
    $event = Event::factory()->standalone()->create();
    $student = Student::factory()->create();
    $otherStudent = Student::factory()->create();
    EventAttendee::factory()->for($event)->for($student, 'attendee')->create();
    EventAttendee::factory()->for($event)->for($otherStudent->user, 'attendee')->create();
    EmergencyContact::factory()->forStudent($student)->create(['phone_number' => '(313) 555-0123', 'wants_text_updates' => true]);
    EmergencyContact::factory()->forStudent($otherStudent)->create(['phone_number' => '(313) 555-0999', 'wants_text_updates' => true]);

    expect(array_keys(app(EventTextRecipients::class)->resolve(Event::query()->whereKey($event)->get())['recipients']))->toBe(['+13135550123']);
});

it('uses the latest completed waiver even if expired and does not revive old consent', function (): void {
    $event = Event::factory()->create();
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create(['course_id' => $event->course_id]);
    EmergencyContact::factory()->forStudent($student)->create(['phone_number' => '(313) 555-0123', 'wants_text_updates' => true]);
    $latest = EmergencyContact::factory()->forStudent($student)->create(['phone_number' => '(313) 555-0999', 'wants_text_updates' => false]);
    $latest->studentWaiver->userForm->form->update(['valid_until' => now()->subDay()]);
    $resolver = app(EventTextRecipients::class);
    $events = Event::query()->whereKey($event)->get();
    expect($resolver->resolve($events)['recipients'])->toBe([]);
    $latest->update(['wants_text_updates' => true]);
    expect(array_keys($resolver->resolve($events)['recipients']))->toBe(['+13135550999']);
    $latest->update(['phone_number' => 'invalid']);
    expect($resolver->resolve($events)['recipients'])->toBe([])
        ->and($resolver->resolve($events)['warnings'])->toHaveCount(2);
});

it('selects the inclusive local cutoff through local midnight including cancelled events across DST', function (string $date, string $utcCutoff, string $utcNextDay): void {
    config()->set('app.display_timezone', 'America/Detroit');
    $before = Event::factory()->standalone()->create(['start_time' => Carbon\Carbon::parse($utcCutoff)->subSecond()]);
    $at = Event::factory()->standalone()->create(['start_time' => $utcCutoff]);
    $cancelled = Event::factory()->standalone()->create(['start_time' => Carbon\Carbon::parse($utcNextDay)->subSecond(), 'cancelled_at' => now()]);
    Event::factory()->standalone()->create(['start_time' => $utcNextDay]);
    Event::factory()->standalone()->create(['start_time' => null]);
    $ids = app(EventTextRecipients::class)->forDate(auth()->user(), $date, '00:00')->modelKeys();
    expect($ids)->toBe([$at->id, $cancelled->id])->not->toContain($before->id);
})->with([
    ['2026-03-08', '2026-03-08 05:00:00', '2026-03-09 04:00:00'],
    ['2026-11-01', '2026-11-01 04:00:00', '2026-11-02 05:00:00'],
]);

it('rejects nonexistent spring-forward cutoff times', function (): void {
    config()->set('app.display_timezone', 'America/Detroit');
    expect(fn () => app(EventTextRecipients::class)->forDate(auth()->user(), '2026-03-08', '02:30'))->toThrow(ValidationException::class);
});

it('enforces event access for delegated texting staff and denies teachers by default', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $event = Event::factory()->standalone()->create();
    $resolver = app(EventTextRecipients::class);
    expect(fn () => $resolver->authorizedEvents($teacher, [$event->id]))->toThrow(AuthorizationException::class);
    $teacher->givePermissionTo('Send:TextMessage');
    expect(fn () => $resolver->authorizedEvents($teacher, [$event->id]))->toThrow(AuthorizationException::class);
    $event->teachers()->attach($teacher);
    expect($resolver->authorizedEvents($teacher, [$event->id])->modelKeys())->toBe([$event->id]);
});
