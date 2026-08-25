<?php

declare(strict_types=1);

use App\Actions\Events\CancelEvent;
use App\Actions\RecurringPrivateLessons\BillRecurringPrivateLessonBillingPeriod;
use App\Actions\RecurringPrivateLessons\CreateRecurringPrivateLesson;
use App\Actions\RecurringPrivateLessons\RemoveRecurringPrivateLessonCharge;
use App\Actions\RecurringPrivateLessons\RescheduleRecurringPrivateLessonCharge;
use App\Actions\RecurringPrivateLessons\SynchronizeRecurringPrivateLessonCharges;
use App\Actions\RecurringPrivateLessons\UpdateRecurringPrivateLessonStatus;
use App\Actions\Store\AddToCart;
use App\Actions\Store\CompleteOrder;
use App\Actions\Store\CreateOrder;
use App\Enums\CourseSemester;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonCoverageStatus;
use App\Enums\RecurringPrivateLessonResolutionType;
use App\Enums\RecurringPrivateLessonStatus;
use App\Enums\ScheduleFrequency;
use App\Models\CartItem;
use App\Models\Event;
use App\Models\Holiday;
use App\Models\Product;
use App\Models\RecurringPrivateLesson;
use App\Models\RecurringPrivateLessonBillingPeriod;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\Student;
use App\Models\User;
use App\Services\Mail\RecurringPrivateLessonContentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Kyle\FilamentMailManager\Mail\ManagedMail;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    $this->owner = User::factory()->isOwner()->create(['email' => 'owner@example.com']);
    $this->household = User::factory()->create(['email' => 'household@example.com']);
    $this->student = Student::factory()->for($this->household)->create();
    $this->teacher = User::factory()->isTeacher()->create(['email' => 'teacher@example.com']);
});

it('uses the scheduled billed paid lifecycle without waiver schema', function (): void {
    expect(array_column(RecurringPrivateLessonChargeStatus::cases(), 'value'))
        ->toBe([
            'Scheduled',
            'Billed',
            'Paid',
            'Cancelled',
            'Credited',
            'Refunded',
        ])
        ->and(Schema::hasColumns('recurring_private_lesson_charges', ['billed_at', 'billed_by_user_id']))
        ->toBeTrue()
        ->and(Schema::hasColumns('recurring_private_lesson_charges', [
            'waiver_reason',
            'waived_at',
            'waived_by_user_id',
        ]))->toBeFalse()
        ->and(Schema::hasColumns('recurring_private_lesson_billing_periods', [
            'last_billed_at',
            'last_billed_by_user_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('recurring_private_lesson_charges', 'replacement_charge_id'))->toBeFalse()
        ->and(Schema::hasColumns('recurring_private_lesson_coverages', [
            'original_charge_id',
            'current_charge_id',
        ]))->toBeFalse()
        ->and(Schema::hasColumn(
            'recurring_private_lesson_coverages',
            'recurring_private_lesson_charge_id',
        ))->toBeTrue()
        ->and(Schema::hasColumn('recurring_private_lesson_charges', 'reschedule_history'))->toBeTrue();
});

it('permanently removes a scheduled lesson and its empty billing dependencies', function (): void {
    $series = managementSeries($this->household, $this->student, $this->teacher);
    $charge = $series->charges->sole();
    $eventId = $charge->event_id;
    $productId = $charge->product->id;
    $billingPeriodId = $charge->recurring_private_lesson_billing_period_id;
    CartItem::factory()->for($this->household)->for($charge->product)->create();

    app(RemoveRecurringPrivateLessonCharge::class)->handle(
        $charge,
        $this->owner,
        'Teacher is no longer available',
    );

    assertDatabaseMissing(RecurringPrivateLessonCharge::class, ['id' => $charge->id]);
    assertDatabaseMissing(Event::class, ['id' => $eventId]);
    assertDatabaseMissing(Product::class, ['id' => $productId]);
    assertDatabaseMissing(CartItem::class, ['product_id' => $productId]);
    assertDatabaseMissing(RecurringPrivateLessonBillingPeriod::class, ['id' => $billingPeriodId]);

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'recurring-private-lesson-removed'
        && $mail->hasTo('household@example.com'));
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'recurring-private-lesson-removed'
        && $mail->hasTo('teacher@example.com'));
    Mail::assertNotQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'recurring-private-lesson-removed'
        && $mail->hasTo('owner@example.com'));
});

it('cancels and retains a billed lesson while removing it from carts', function (): void {
    $series = managementSeries($this->household, $this->student, $this->teacher);
    $charge = $series->charges->sole();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($charge->billingPeriod, $this->owner);
    app(AddToCart::class)->handle($this->household, $charge->product);
    Mail::fake();

    app(RemoveRecurringPrivateLessonCharge::class)->handle(
        $charge,
        $this->owner,
        'The studio is unavailable',
    );

    expect($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Cancelled)
        ->and($charge->event->refresh()->cancellation_reason)->toBe('The studio is unavailable')
        ->and($charge->product->refresh()->is_active)->toBeFalse();
    assertDatabaseHas(RecurringPrivateLessonBillingPeriod::class, ['id' => $charge->recurring_private_lesson_billing_period_id]);
    assertDatabaseMissing(CartItem::class, ['product_id' => $charge->product->id]);
});

it('routes recurring private lesson cancellations through the lesson management action', function (): void {
    $series = managementSeries($this->household, $this->student, $this->teacher);
    $charge = $series->charges->sole();

    expect(fn () => app(CancelEvent::class)->handle(
        $charge->event,
        $this->owner,
        'Attempted from the general calendar',
        false,
    ))->toThrow(DomainException::class, 'Use the recurring private lesson Remove action')
        ->and($charge->event->refresh()->isCancelled())->toBeFalse()
        ->and($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Scheduled);
});

it('credits a removed paid lesson immediately without an intermediate status', function (): void {
    $series = managementSeries($this->household, $this->student, $this->teacher);
    $charge = $series->charges->sole();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($charge->billingPeriod, $this->owner);
    app(AddToCart::class)->handle($this->household, $charge->product);
    $order = app(CreateOrder::class)->handle($this->household);
    app(CompleteOrder::class)->handle($order);
    $coverageId = $charge->refresh()->coverage->id;

    app(RemoveRecurringPrivateLessonCharge::class)->handle(
        $charge,
        $this->owner,
        'Dancer cannot attend',
        RecurringPrivateLessonResolutionType::Credit,
    );

    expect($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Credited)
        ->and($charge->coverage->id)->toBe($coverageId)
        ->and($charge->coverage->status)->toBe(RecurringPrivateLessonCoverageStatus::Credited)
        ->and($charge->event->cancellation_reason)->toBe('Dancer cannot attend')
        ->and($charge->product->is_active)->toBeFalse();
});

it('requires a credit or refund choice when removing a paid lesson', function (): void {
    $series = managementSeries($this->household, $this->student, $this->teacher);
    $charge = $series->charges->sole();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($charge->billingPeriod, $this->owner);
    app(AddToCart::class)->handle($this->household, $charge->product);
    app(CompleteOrder::class)->handle(app(CreateOrder::class)->handle($this->household));

    expect(fn () => app(RemoveRecurringPrivateLessonCharge::class)->handle(
        $charge,
        $this->owner,
        'Dancer cannot attend',
    ))->toThrow(InvalidArgumentException::class, 'Choose whether to issue credit or refund')
        ->and($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Paid)
        ->and($charge->event->refresh()->isCancelled())->toBeFalse();
});

it('reschedules a lesson across months while preserving duration and resetting reminders', function (): void {
    $series = managementSeries($this->household, $this->student, $this->teacher);
    $charge = $series->charges->sole();
    $oldPeriodId = $charge->recurring_private_lesson_billing_period_id;
    $charge->update([
        'seven_day_reminder_sent_at' => now(),
        'two_day_reminder_sent_at' => now(),
    ]);

    app(RescheduleRecurringPrivateLessonCharge::class)->handle(
        $charge,
        CarbonImmutable::parse('2026-09-03 18:15:47', 'America/New_York'),
        $this->owner,
        'Teacher requested a different evening',
    );

    $charge->refresh()->load(['event', 'billingPeriod', 'product']);

    expect($charge->status)->toBe(RecurringPrivateLessonChargeStatus::Scheduled)
        ->and($charge->event->start_time->timezone('America/New_York')->format('Y-m-d H:i:s'))->toBe('2026-09-03 18:15:00')
        ->and((int) $charge->event->start_time->diffInMinutes($charge->event->end_time))->toBe(90)
        ->and($charge->billingPeriod->period_start->toDateString())->toBe('2026-09-01')
        ->and($charge->product->name)->toContain('Sep 3, 2026 6:15 PM')
        ->and($charge->product->available_until->equalTo($charge->event->start_time->copy()->subDay()))->toBeTrue()
        ->and($charge->seven_day_reminder_sent_at)->toBeNull()
        ->and($charge->two_day_reminder_sent_at)->toBeNull()
        ->and($charge->reschedule_history)->toHaveCount(1)
        ->and($charge->reschedule_history[0]['reason'])->toBe('Teacher requested a different evening');
    assertDatabaseMissing(RecurringPrivateLessonBillingPeriod::class, ['id' => $oldPeriodId]);
    $emailPayload = app(RecurringPrivateLessonContentService::class)->forManagedChange(
        $charge,
        CarbonImmutable::parse('2026-08-10 17:00', 'America/New_York'),
        'Teacher requested a different evening',
    );

    expect($emailPayload['tokens']['lesson.previous_starts_at'])->toContain('August 10, 2026 at 5:00 PM')
        ->and($emailPayload['tokens']['lesson.starts_at'])->toContain('September 3, 2026 at 6:15 PM')
        ->and($emailPayload['tokens']['change.reason'])->toBe('Teacher requested a different evening');

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'recurring-private-lesson-rescheduled'
        && $mail->hasTo('household@example.com'));
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'recurring-private-lesson-rescheduled'
        && $mail->hasTo('teacher@example.com'));
    Mail::assertNotQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'recurring-private-lesson-rescheduled'
        && $mail->hasTo('owner@example.com'));
});

it('enforces the unpaid cutoff and holiday conflict when rescheduling', function (): void {
    $series = managementSeries($this->household, $this->student, $this->teacher);
    $charge = $series->charges->sole();

    expect(fn () => app(RescheduleRecurringPrivateLessonCharge::class)->handle(
        $charge,
        now()->addHours(12),
        $this->owner,
        'Requested a sooner time',
    ))->toThrow(InvalidArgumentException::class, 'more than 24 hours');

    Holiday::factory()->create([
        'name' => 'Studio Closed',
        'starts_on' => '2026-09-03',
        'ends_on' => '2026-09-03',
    ]);

    expect(fn () => app(RescheduleRecurringPrivateLessonCharge::class)->handle(
        $charge,
        CarbonImmutable::parse('2026-09-03 18:00', 'America/New_York'),
        $this->owner,
        'Requested a closed date',
    ))->toThrow(ValidationException::class);
});

it('allows a paid lesson to move within 24 hours and preserves its paid state', function (): void {
    $series = managementSeries($this->household, $this->student, $this->teacher);
    $charge = $series->charges->sole();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($charge->billingPeriod, $this->owner);
    app(AddToCart::class)->handle($this->household, $charge->product);
    app(CompleteOrder::class)->handle(app(CreateOrder::class)->handle($this->household));
    $coverageId = $charge->refresh()->coverage->id;

    app(RescheduleRecurringPrivateLessonCharge::class)->handle(
        $charge,
        CarbonImmutable::parse('2026-08-01 20:30:29', 'America/New_York'),
        $this->owner,
        'Family requested a same-day move',
    );

    expect($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Paid)
        ->and($charge->coverage->id)->toBe($coverageId)
        ->and($charge->event->start_time->timezone('America/New_York')->format('Y-m-d H:i:s'))->toBe('2026-08-01 20:30:00');
});

it('rejects household and teacher lesson management attempts', function (): void {
    $series = managementSeries($this->household, $this->student, $this->teacher);
    $charge = $series->charges->sole();

    expect(fn () => app(RescheduleRecurringPrivateLessonCharge::class)->handle(
        $charge,
        CarbonImmutable::parse('2026-08-12 18:00', 'America/New_York'),
        $this->household,
        'Household requested a move',
    ))->toThrow(InvalidArgumentException::class, 'Only owners and super admins')
        ->and(fn () => app(RemoveRecurringPrivateLessonCharge::class)->handle(
            $charge,
            $this->teacher,
            'Teacher tried to remove it',
        ))->toThrow(InvalidArgumentException::class, 'Only owners and super admins');
});

it('stops unpaid operations for an inactive series and restores them when reactivated', function (RecurringPrivateLessonStatus $status): void {
    $series = managementSeries($this->household, $this->student, $this->teacher);
    $charge = $series->charges->sole();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($charge->billingPeriod, $this->owner);
    app(AddToCart::class)->handle($this->household, $charge->product);

    app(UpdateRecurringPrivateLessonStatus::class)->handle($series, $status);

    expect($series->refresh()->status)->toBe($status)
        ->and($charge->product->refresh()->is_active)->toBeFalse()
        ->and($charge->refresh()->getAvailableCapacity($this->household))->toBe(0)
        ->and(fn () => app(BillRecurringPrivateLessonBillingPeriod::class)->handle(
            $charge->billingPeriod,
            $this->owner,
        ))->toThrow(InvalidArgumentException::class, 'Only active recurring private lesson series may be billed')
        ->and(fn () => app(RescheduleRecurringPrivateLessonCharge::class)->handle(
            $charge,
            CarbonImmutable::parse('2026-08-12 18:00', 'America/New_York'),
            $this->owner,
            'Attempted while the series is inactive',
        ))->toThrow(InvalidArgumentException::class, 'Only lessons in an active recurring series may be rescheduled');

    assertDatabaseMissing(CartItem::class, [
        'user_id' => $this->household->id,
        'product_id' => $charge->product->id,
    ]);

    Event::factory()->create([
        'course_id' => $series->course_id,
        'start_time' => CarbonImmutable::parse('2026-08-17 17:00', 'America/New_York'),
        'end_time' => CarbonImmutable::parse('2026-08-17 18:30', 'America/New_York'),
    ]);

    expect(app(SynchronizeRecurringPrivateLessonCharges::class)->handle($series))->toBe(0)
        ->and($series->charges()->count())->toBe(1);

    app(UpdateRecurringPrivateLessonStatus::class)->handle(
        $series,
        RecurringPrivateLessonStatus::Active,
    );

    expect($series->refresh()->status)->toBe(RecurringPrivateLessonStatus::Active)
        ->and($charge->product->refresh()->is_active)->toBeTrue()
        ->and($charge->refresh()->getAvailableCapacity($this->household))->toBe(1)
        ->and($series->charges()->count())->toBe(2);
})->with([
    RecurringPrivateLessonStatus::Completed,
    RecurringPrivateLessonStatus::Cancelled,
]);

function managementSeries(User $household, Student $student, User $teacher): RecurringPrivateLesson
{
    return app(CreateRecurringPrivateLesson::class)->handle(
        $household,
        $student,
        [$teacher->id],
        'Ballet Private Lesson',
        null,
        CourseSemester::Fall,
        6000,
        CarbonImmutable::parse('2026-08-10 17:00', 'America/New_York'),
        90,
        CarbonImmutable::parse('2026-08-10', 'America/New_York'),
        ScheduleFrequency::Weekly,
    );
}
