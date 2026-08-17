<?php

declare(strict_types=1);

use App\Actions\Mail\SendRecurringPrivateLessonPaymentReminders;
use App\Actions\RecurringPrivateLessons\BillRecurringPrivateLessonBillingPeriod;
use App\Actions\RecurringPrivateLessons\CancelUnpaidRecurringPrivateLessons;
use App\Actions\RecurringPrivateLessons\CreateRecurringPrivateLesson;
use App\Enums\CourseSemester;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\ScheduleFrequency;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;

beforeEach(function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-01 08:00', 'America/New_York'));
    $this->owner = User::factory()->isOwner()->create(['email' => 'owner@example.com']);
    $this->household = User::factory()->create(['email' => 'household@example.com']);
    $this->student = Student::factory()->for($this->household)->create();
    $this->teacher = User::factory()->isTeacher()->create(['email' => 'teacher@example.com']);
});

it('sends staff-only scheduled reminders and includes the household after a charge is billed', function (): void {
    $series = reminderSeries($this->household, $this->student, $this->teacher, '2026-08-08 17:00');
    $scheduledCharge = $series->charges->first();

    $scheduledResult = app(SendRecurringPrivateLessonPaymentReminders::class)->handle(now());

    expect($scheduledResult['charges_processed'])->toBe(1)
        ->and($scheduledCharge->refresh()->seven_day_reminder_sent_at)->not->toBeNull();
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'recurring-private-lesson-payment-reminder'
        && $mail->hasTo('owner@example.com'));
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'recurring-private-lesson-payment-reminder'
        && $mail->hasTo('teacher@example.com'));
    Mail::assertNotQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->hasTo('household@example.com'));

    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-06 08:00', 'America/New_York'));
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($series->billingPeriods->first(), $this->owner);
    $sentResult = app(SendRecurringPrivateLessonPaymentReminders::class)->handle(now());

    expect($sentResult['charges_processed'])->toBe(1)
        ->and($scheduledCharge->refresh()->two_day_reminder_sent_at)->not->toBeNull();
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'recurring-private-lesson-payment-reminder'
        && $mail->hasTo('household@example.com'));
});

it('automatically cancels billed or scheduled unpaid lessons at the 24 hour cutoff', function (): void {
    $series = reminderSeries($this->household, $this->student, $this->teacher, '2026-08-03 17:00');
    $charge = $series->charges->first();
    $this->travelTo(CarbonImmutable::parse('2026-08-02 17:00', 'America/New_York'));

    expect(app(CancelUnpaidRecurringPrivateLessons::class)->handle())->toBe(1)
        ->and($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Cancelled)
        ->and($charge->automatically_cancelled_at)->not->toBeNull()
        ->and($charge->event->refresh()->isCancelled())->toBeTrue();

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'recurring-private-lesson-automatic-cancellation'
        && $mail->hasTo('household@example.com'));
});

function reminderSeries(User $household, Student $student, User $teacher, string $startsAt): App\Models\RecurringPrivateLesson
{
    return app(CreateRecurringPrivateLesson::class)->handle(
        $household,
        $student,
        [$teacher->id],
        'Private Lesson',
        null,
        CourseSemester::Fall,
        6000,
        CarbonImmutable::parse($startsAt, 'America/New_York'),
        60,
        CarbonImmutable::parse($startsAt, 'America/New_York'),
        ScheduleFrequency::Weekly,
    );
}
