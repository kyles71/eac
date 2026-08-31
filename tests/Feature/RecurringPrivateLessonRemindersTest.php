<?php

declare(strict_types=1);

use App\Actions\Mail\SendRecurringPrivateLessonBillingSummary;
use App\Actions\Mail\SendRecurringPrivateLessonPaymentReminders;
use App\Actions\RecurringPrivateLessons\BillRecurringPrivateLessonBillingPeriod;
use App\Actions\RecurringPrivateLessons\CancelUnpaidRecurringPrivateLessons;
use App\Actions\RecurringPrivateLessons\CreateRecurringPrivateLesson;
use App\Actions\RecurringPrivateLessons\UpdateRecurringPrivateLessonStatus;
use App\Actions\Store\AddToCart;
use App\Enums\CourseSemester;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonStatus;
use App\Enums\ScheduleFrequency;
use App\Models\CartItem;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;

use function Pest\Laravel\assertDatabaseMissing;

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

it('emails EAC seven days before month-end with only next months scheduled lessons', function (): void {
    config()->set(
        'mail.recurring_private_lesson_billing_summary_recipient',
        'eacdance@outlook.com',
    );
    $septemberSeries = reminderSeries(
        $this->household,
        $this->student,
        $this->teacher,
        '2026-09-08 17:00',
    );
    $septemberSeries->course->update(['name' => 'September Ballet Private']);
    $billedSeries = reminderSeries(
        $this->household,
        Student::factory()->for($this->household)->create(),
        $this->teacher,
        '2026-09-09 18:00',
    );
    $billedSeries->course->update(['name' => 'Already Billed Private']);
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle(
        $billedSeries->billingPeriods->first(),
        $this->owner,
    );
    $octoberSeries = reminderSeries(
        $this->household,
        Student::factory()->for($this->household)->create(),
        $this->teacher,
        '2026-10-01 17:00',
    );
    $octoberSeries->course->update(['name' => 'October Jazz Private']);
    Mail::fake();

    $summary = app(SendRecurringPrivateLessonBillingSummary::class);

    expect($summary->handle(CarbonImmutable::parse('2026-08-23 08:00', 'America/New_York')))
        ->toBe(['lessons' => 0, 'email_queued' => false]);
    Mail::assertNothingQueued();

    expect($summary->handle(CarbonImmutable::parse('2026-08-24 08:00', 'America/New_York')))
        ->toBe(['lessons' => 1, 'email_queued' => true]);

    $definition = app(EmailTypeRegistry::class)->get('recurring-private-lesson-billing-summary');

    expect(array_keys($definition->tokensByKey()))
        ->toContain('billing.month', 'billing.lesson_count', 'billing.total');
    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'recurring-private-lesson-billing-summary'
            && $mail->hasTo('eacdance@outlook.com')
            && str_contains($rendered->subject, 'September 2026 recurring private lessons awaiting billing')
            && str_contains($rendered->html, 'September Ballet Private')
            && str_contains($rendered->html, '$60.00')
            && ! str_contains($rendered->html, 'Already Billed Private')
            && ! str_contains($rendered->html, 'October Jazz Private');
    });
});

it('schedules the next-month billing summary daily for its month-end date check', function (): void {
    $event = collect(Schedule::events())
        ->first(fn ($event): bool => str_contains(
            $event->command ?? '',
            'private-lessons:send-billing-summary',
        ));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8 * * *')
        ->and($event->timezone)->toBe('America/New_York')
        ->and($event->withoutOverlapping)->toBeTrue();

    $this->artisan('private-lessons:send-billing-summary')
        ->expectsOutput('No recurring private lesson billing summary was queued.')
        ->assertSuccessful();
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

it('removes an automatically cancelled billed lesson from the household cart', function (): void {
    $series = reminderSeries($this->household, $this->student, $this->teacher, '2026-08-03 17:00');
    $charge = $series->charges->first();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($series->billingPeriods->first(), $this->owner);
    app(AddToCart::class)->handle($this->household, $charge->product);
    $this->travelTo(CarbonImmutable::parse('2026-08-02 17:00', 'America/New_York'));

    expect(app(CancelUnpaidRecurringPrivateLessons::class)->handle())->toBe(1);

    assertDatabaseMissing(CartItem::class, [
        'user_id' => $this->household->id,
        'product_id' => $charge->product->id,
    ]);
});

it('skips reminders and automatic cancellation for an inactive series', function (): void {
    $reminderSeries = reminderSeries($this->household, $this->student, $this->teacher, '2026-08-08 17:00');
    app(UpdateRecurringPrivateLessonStatus::class)->handle(
        $reminderSeries,
        RecurringPrivateLessonStatus::Completed,
    );

    expect(app(SendRecurringPrivateLessonPaymentReminders::class)->handle(now()))->toBe([
        'charges_processed' => 0,
        'emails_queued' => 0,
    ]);

    $cancellationSeries = reminderSeries(
        $this->household,
        Student::factory()->for($this->household)->create(),
        $this->teacher,
        '2026-08-03 17:00',
    );
    app(UpdateRecurringPrivateLessonStatus::class)->handle(
        $cancellationSeries,
        RecurringPrivateLessonStatus::Cancelled,
    );
    $this->travelTo(CarbonImmutable::parse('2026-08-02 17:00', 'America/New_York'));

    expect(app(CancelUnpaidRecurringPrivateLessons::class)->handle())->toBe(0)
        ->and($cancellationSeries->charges->first()->event->refresh()->isCancelled())->toBeFalse();
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
