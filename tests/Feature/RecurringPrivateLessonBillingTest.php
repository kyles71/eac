<?php

declare(strict_types=1);

use App\Actions\RecurringPrivateLessons\BillRecurringPrivateLessonBillingPeriod;
use App\Actions\RecurringPrivateLessons\CreateRecurringPrivateLesson;
use App\Actions\RecurringPrivateLessons\RemoveRecurringPrivateLessonCharge;
use App\Actions\RecurringPrivateLessons\RescheduleRecurringPrivateLessonCharge;
use App\Actions\RecurringPrivateLessons\SynchronizeRecurringPrivateLessonCharges;
use App\Actions\Store\AddToCart;
use App\Actions\Store\CompleteOrder;
use App\Actions\Store\CreateOrder;
use App\Contracts\StripeServiceContract;
use App\Enums\CourseSemester;
use App\Enums\ProductType;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonCoverageStatus;
use App\Enums\RecurringPrivateLessonResolutionType;
use App\Enums\ScheduleFrequency;
use App\Models\CreditGrant;
use App\Models\DiscountCode;
use App\Models\PaymentPlanTemplate;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Stripe\Refund;

beforeEach(function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    $this->owner = User::factory()->isOwner()->create();
    $this->household = User::factory()->create();
    $this->student = Student::factory()->for($this->household)->create();
    $this->teacher = User::factory()->isTeacher()->create();
    $this->series = app(CreateRecurringPrivateLesson::class)->handle(
        $this->household,
        $this->student,
        [$this->teacher->id],
        'Ballet Private Lesson',
        null,
        CourseSemester::Fall,
        6000,
        CarbonImmutable::parse('2026-08-10 17:00', 'America/New_York'),
        60,
        CarbonImmutable::parse('2026-09-14', 'America/New_York'),
        ScheduleFrequency::Weekly,
    );
});

it('releases one month for payment and supports paying one lesson with a promo code', function (): void {
    $period = $this->series->billingPeriods->first();
    $billedCount = app(BillRecurringPrivateLessonBillingPeriod::class)->handle($period, $this->owner);
    $charge = $period->charges()->orderBy('id')->firstOrFail();
    $discount = DiscountCode::factory()->percentage(25)->create();

    app(AddToCart::class)->handle($this->household, $charge->product);
    $order = app(CreateOrder::class)->handle($this->household, $discount);

    expect($billedCount)->toBe(4)
        ->and($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Billed)
        ->and($charge->product->is_active)->toBeTrue()
        ->and($order->orderItems->sole()->discount_allocated)->toBe(1500)
        ->and($order->orderItems->sole()->stripe_allocated)->toBe(4500)
        ->and(app(CompleteOrder::class)->handle($order))->toBeTrue()
        ->and($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Paid)
        ->and($charge->coverage->netPaidAmount())->toBe(4500)
        ->and($charge->coverage->discount_amount)->toBe(1500);
});

it('does not offer payment plans for recurring private lesson charges', function (): void {
    $period = $this->series->billingPeriods->first();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($period, $this->owner);
    $charge = $period->charges->first();
    $template = PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Any,
        'min_price' => 1,
        'max_price' => 100_000,
    ]);

    expect($template->matchesProduct($charge->product, $charge->amount))->toBeFalse();

    app(AddToCart::class)->handle($this->household, $charge->product);
    app(CreateOrder::class)->handle($this->household, paymentPlanTemplate: $template);
})->throws(InvalidArgumentException::class, 'Payment plans are not available');

it('updates only scheduled price snapshots when the recurring rate changes', function (): void {
    $august = $this->series->billingPeriods->first(
        fn ($period): bool => $period->period_start->toDateString() === '2026-08-01',
    );
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($august, $this->owner);
    $this->series->update(['lesson_price' => 7000]);
    app(SynchronizeRecurringPrivateLessonCharges::class)->handle($this->series);

    expect($august->charges()->pluck('amount')->unique()->all())->toBe([6000])
        ->and($this->series->charges()
            ->where('status', RecurringPrivateLessonChargeStatus::Scheduled)
            ->pluck('amount')->unique()->all())->toBe([7000]);
});

it('keeps paid coverage attached when rescheduling a paid lesson', function (): void {
    $period = $this->series->billingPeriods->first();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($period, $this->owner);
    $charges = $period->charges()->orderBy('id')->get();
    $paidCharge = $charges->first();
    app(AddToCart::class)->handle($this->household, $paidCharge->product);
    $order = app(CreateOrder::class)->handle($this->household);
    app(CompleteOrder::class)->handle($order);
    $coverageId = $paidCharge->refresh()->coverage->id;

    app(RescheduleRecurringPrivateLessonCharge::class)->handle(
        $paidCharge,
        CarbonImmutable::parse('2026-08-11 18:30', 'America/New_York'),
        $this->owner,
        'Moved to a new date',
    );

    expect($paidCharge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Paid)
        ->and($paidCharge->coverage->id)->toBe($coverageId)
        ->and($paidCharge->event->start_time->timezone('America/New_York')->format('Y-m-d H:i'))
        ->toBe('2026-08-11 18:30');
});

it('issues unrestricted store credit for the net amount paid without returning promo value', function (): void {
    $period = $this->series->billingPeriods->first();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($period, $this->owner);
    $charge = $period->charges()->orderBy('id')->firstOrFail();
    $discount = DiscountCode::factory()->percentage(25)->create();
    app(AddToCart::class)->handle($this->household, $charge->product);
    $order = app(CreateOrder::class)->handle($this->household, $discount);
    app(CompleteOrder::class)->handle($order);
    app(RemoveRecurringPrivateLessonCharge::class)->handle(
        $charge,
        $this->owner,
        'Credit for a future lesson',
        RecurringPrivateLessonResolutionType::Credit,
    );

    $grant = CreditGrant::query()
        ->where('source_type', $charge->coverage->getMorphClass())
        ->where('source_id', $charge->coverage->id)
        ->sole();

    expect($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Credited)
        ->and($charge->coverage->refresh()->status)->toBe(RecurringPrivateLessonCoverageStatus::Credited)
        ->and($grant->initial_amount)->toBe(4500)
        ->and($grant->hasRestrictions())->toBeFalse();

    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'recurring-private-lesson-removed'
            && str_contains($rendered->html, 'Payment resolution:')
            && str_contains($rendered->html, '$45.00 in unrestricted store credit was issued');
    });
});

it('never issues cancellation credit above the net lesson price', function (): void {
    $period = $this->series->billingPeriods->first();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($period, $this->owner);
    $charge = $period->charges()->orderBy('id')->firstOrFail();
    $discount = DiscountCode::factory()->percentage(25)->create();
    app(AddToCart::class)->handle($this->household, $charge->product);
    $order = app(CreateOrder::class)->handle($this->household, $discount);
    app(CompleteOrder::class)->handle($order);
    $charge->refresh()->coverage->update([
        'gross_amount' => 6100,
        'stripe_amount' => 4600,
    ]);

    app(RemoveRecurringPrivateLessonCharge::class)->handle(
        $charge,
        $this->owner,
        'Credit for a future lesson',
        RecurringPrivateLessonResolutionType::Credit,
    );

    $grant = CreditGrant::query()
        ->where('source_type', $charge->coverage->getMorphClass())
        ->where('source_id', $charge->coverage->id)
        ->sole();

    expect($charge->coverage->netPaidAmount())->toBe(4500)
        ->and($grant->initial_amount)->toBe(4500);
});

it('partially refunds only the Stripe amount allocated to a cancelled paid lesson', function (): void {
    $period = $this->series->billingPeriods->first();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($period, $this->owner);
    $charge = $period->charges()->orderBy('id')->firstOrFail();
    app(AddToCart::class)->handle($this->household, $charge->product);
    $order = app(CreateOrder::class)->handle($this->household);
    $order->update(['stripe_payment_intent_id' => 'pi_private_lesson']);
    $stripe = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $stripe);
    app(CompleteOrder::class)->handle($order);
    $coverageId = $charge->refresh()->coverage->id;
    $transactionLevelBeforeRefund = DB::connection()->transactionLevel();
    $idempotencyKey = "recurring-private-lesson-coverage-{$coverageId}-refund-".hash('sha256', 'pi_private_lesson');
    $stripe->shouldReceive('refundPaymentIntent')
        ->once()
        ->with(
            'pi_private_lesson',
            6000,
            [],
            $idempotencyKey,
        )
        ->andReturnUsing(function () use ($transactionLevelBeforeRefund): Refund {
            expect(DB::connection()->transactionLevel())->toBeGreaterThan($transactionLevelBeforeRefund);

            return Refund::constructFrom(['id' => 're_private_lesson']);
        });
    app(RemoveRecurringPrivateLessonCharge::class)->handle(
        $charge,
        $this->owner,
        'Partial refund for one cancelled lesson',
        RecurringPrivateLessonResolutionType::Refund,
    );

    expect($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Refunded)
        ->and($charge->coverage->refresh()->status)->toBe(RecurringPrivateLessonCoverageStatus::Refunded)
        ->and($charge->coverage->stripe_refund_id)->toBe('re_private_lesson');
});

it('keeps a paid lesson active when its Stripe refund fails', function (): void {
    $period = $this->series->billingPeriods->first();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($period, $this->owner);
    $charge = $period->charges()->orderBy('id')->firstOrFail();
    app(AddToCart::class)->handle($this->household, $charge->product);
    $order = app(CreateOrder::class)->handle($this->household);
    $order->update(['stripe_payment_intent_id' => 'pi_failed_private_lesson_refund']);
    $stripe = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $stripe);
    app(CompleteOrder::class)->handle($order);
    $coverageId = $charge->refresh()->coverage->id;
    $idempotencyKey = "recurring-private-lesson-coverage-{$coverageId}-refund-".hash(
        'sha256',
        'pi_failed_private_lesson_refund',
    );
    $stripe->shouldReceive('refundPaymentIntent')
        ->once()
        ->with(
            'pi_failed_private_lesson_refund',
            6000,
            [],
            $idempotencyKey,
        )
        ->andThrow(new RuntimeException('Stripe refund failed'));

    expect(fn () => app(RemoveRecurringPrivateLessonCharge::class)->handle(
        $charge,
        $this->owner,
        'Refund could not be completed',
        RecurringPrivateLessonResolutionType::Refund,
    ))->toThrow(RuntimeException::class, 'Stripe refund failed')
        ->and($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Paid)
        ->and($charge->event->refresh()->isCancelled())->toBeFalse()
        ->and($charge->coverage->refresh()->status)->toBe(RecurringPrivateLessonCoverageStatus::Active)
        ->and($charge->coverage->stripe_refund_id)->toBeNull();
});

it('reuses the same Stripe idempotency key after a post-refund database rollback', function (): void {
    $period = $this->series->billingPeriods->first();
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($period, $this->owner);
    $charge = $period->charges()->orderBy('id')->firstOrFail();
    app(AddToCart::class)->handle($this->household, $charge->product);
    $order = app(CreateOrder::class)->handle($this->household);
    $order->update(['stripe_payment_intent_id' => 'pi_retry_private_lesson_refund']);
    $stripe = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $stripe);
    app(CompleteOrder::class)->handle($order);
    $coverageId = $charge->refresh()->coverage->id;
    $idempotencyKey = "recurring-private-lesson-coverage-{$coverageId}-refund-".hash(
        'sha256',
        'pi_retry_private_lesson_refund',
    );
    $stripe->shouldReceive('refundPaymentIntent')
        ->twice()
        ->with('pi_retry_private_lesson_refund', 6000, [], $idempotencyKey)
        ->andReturn(
            Refund::constructFrom([]),
            Refund::constructFrom(['id' => 're_retried_private_lesson']),
        );

    expect(fn () => app(RemoveRecurringPrivateLessonCharge::class)->handle(
        $charge,
        $this->owner,
        'Retry the interrupted refund',
        RecurringPrivateLessonResolutionType::Refund,
    ))->toThrow(UnexpectedValueException::class, 'Stripe did not return a refund identifier')
        ->and($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Paid)
        ->and($charge->coverage->refresh()->status)->toBe(RecurringPrivateLessonCoverageStatus::Active)
        ->and($charge->coverage->stripe_refund_id)->toBeNull();

    app(RemoveRecurringPrivateLessonCharge::class)->handle(
        $charge,
        $this->owner,
        'Retry the interrupted refund',
        RecurringPrivateLessonResolutionType::Refund,
    );

    expect($charge->refresh()->status)->toBe(RecurringPrivateLessonChargeStatus::Refunded)
        ->and($charge->coverage->refresh()->status)->toBe(RecurringPrivateLessonCoverageStatus::Refunded)
        ->and($charge->coverage->stripe_refund_id)->toBe('re_retried_private_lesson');
});
