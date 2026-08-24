<?php

declare(strict_types=1);

use App\Actions\CourseHolds\AddCourseHoldToCart;
use App\Actions\CourseHolds\ConvertEnrollmentToCourseHold;
use App\Actions\CourseHolds\CreateCourseHold;
use App\Actions\CourseHolds\UpdateCourseHold;
use App\Actions\Store\AddToCart;
use App\Actions\Store\CancelOrder;
use App\Actions\Store\CompleteOrder;
use App\Actions\Store\CreateOrder;
use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Course;
use App\Models\CourseHold;
use App\Models\CourseHoldSeat;
use App\Models\Enrollment;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\MailManager;

beforeEach(function (): void {
    Mail::fake();

    $this->stripe = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $this->stripe);

    $this->family = User::factory()->create();
    $this->course = Course::factory()->create(['capacity' => 3]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 12_000]);
});

it('creates priced seats that reserve capacity for the selected family', function (): void {
    $hold = app(CreateCourseHold::class)->handle(
        user: $this->family,
        expiresAt: now()->addDays(2),
        lines: [['course_id' => $this->course->id, 'quantity' => 2]],
        createdBy: auth()->user(),
        notes: 'Requested by phone',
    );

    expect($hold->seats)->toHaveCount(2)
        ->and($hold->seats->pluck('locked_unit_price')->unique()->all())->toBe([12_000])
        ->and($this->course->getAvailableCapacity())->toBe(1)
        ->and($this->course->getAvailableCapacity($this->family))->toBe(3)
        ->and($hold->notes)->toBe('Requested by phone');

    Mail::assertQueued(
        ManagedMail::class,
        fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'course-hold-created'
            && $mail->hasTo($this->family->email),
    );
});

it('returns expired or released seats to public capacity', function (): void {
    $hold = app(CreateCourseHold::class)->handle(
        $this->family,
        now()->addDay(),
        [['course_id' => $this->course->id, 'quantity' => 1]],
    );

    expect($this->course->getAvailableCapacity())->toBe(2);

    $hold->update(['expires_at' => now()->subMinute()]);

    expect($this->course->getAvailableCapacity())->toBe(3);

    $hold->update(['expires_at' => now()->addDay()]);
    $hold->seats()->update(['released_at' => now()]);

    expect($this->course->getAvailableCapacity())->toBe(3);
});

it('does not reactivate an expired hold after its seat has sold publicly', function (): void {
    $this->course->update(['capacity' => 1]);
    $hold = app(CreateCourseHold::class)->handle(
        $this->family,
        now()->addHour(),
        [['course_id' => $this->course->id, 'quantity' => 1]],
    );
    $hold->update(['expires_at' => now()->subMinute()]);
    Enrollment::factory()->create(['course_id' => $this->course->id]);

    app(UpdateCourseHold::class)->handle($hold, now()->addDay());
})->throws(InvalidArgumentException::class, 'An expired held class no longer has enough capacity');

it('converts a manual enrollment into a hold without changing occupied capacity', function (): void {
    $student = Student::factory()->for($this->family)->create();
    $enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->family->id,
        'student_id' => $student->id,
        'order_item_id' => null,
    ]);

    expect($this->course->getAvailableCapacity())->toBe(2);

    $hold = app(ConvertEnrollmentToCourseHold::class)->handle(
        $enrollment,
        now()->addDays(2),
        auth()->user(),
    );

    expect(Enrollment::query()->whereKey($enrollment->id)->exists())->toBeFalse()
        ->and($hold->seats)->toHaveCount(1)
        ->and($hold->seats->first()->student_id)->toBe($student->id)
        ->and($this->course->getAvailableCapacity())->toBe(2);
});

it('uses the held price and supports a payment plan after the public price changes', function (): void {
    $hold = app(CreateCourseHold::class)->handle(
        $this->family,
        now()->addDays(2),
        [['course_id' => $this->course->id, 'quantity' => 1]],
    );

    $this->product->update(['price' => 18_000]);
    app(AddCourseHoldToCart::class)->handle($this->family, $hold);

    $template = PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Course,
        'min_price' => 1,
        'max_price' => 50_000,
        'number_of_installments' => 3,
    ]);

    $order = app(CreateOrder::class)->handle(
        user: $this->family,
        paymentPlanTemplate: $template,
    );
    $orderItem = $order->orderItems->sole();

    expect($order->subtotal)->toBe(12_000)
        ->and($order->payment_plan_template_id)->toBe($template->id)
        ->and($order->payment_plan_principal)->toBeGreaterThan(0)
        ->and($order->payment_plan_terms_version_id)->not->toBeNull()
        ->and($orderItem->unit_price)->toBe(12_000)
        ->and($orderItem->course_hold_id)->toBe($hold->id)
        ->and($order->hold_checkout_expires_at)->not->toBeNull()
        ->and($hold->seats->first()->refresh()->claimed_order_item_id)->toBe($orderItem->id);
});

it('keeps ordinary and held-price cart lines separate for the same class', function (): void {
    $hold = app(CreateCourseHold::class)->handle(
        $this->family,
        now()->addDays(2),
        [['course_id' => $this->course->id, 'quantity' => 1]],
    );

    app(AddToCart::class)->handle($this->family, $this->product);
    app(AddCourseHoldToCart::class)->handle($this->family, $hold);

    $cartItems = $this->family->cartItems()->orderBy('course_hold_id')->get();

    expect($cartItems)->toHaveCount(2)
        ->and($cartItems->whereNull('course_hold_id')->sole()->held_unit_price)->toBeNull()
        ->and($cartItems->where('course_hold_id', $hold->id)->sole()->held_unit_price)->toBe(12_000);
});

it('stores purchaser-question answers on a held cart line', function (): void {
    $question = ProductQuestion::factory()->for($this->product)->required()->create([
        'question' => 'Dancer name',
    ]);
    $hold = app(CreateCourseHold::class)->handle(
        $this->family,
        now()->addDays(2),
        [['course_id' => $this->course->id, 'quantity' => 1]],
    );

    $cartItem = app(AddCourseHoldToCart::class)->handle(
        $this->family,
        $hold,
        [$this->course->id => 1],
        [$this->course->id => [
            1 => ["question_{$question->id}" => 'Avery'],
        ]],
    )->sole();

    expect($cartItem->storedQuestionAnswers())->toBe([
        1 => ["question_{$question->id}" => 'Avery'],
    ]);
});

it('fulfills only the purchased held seats and preserves student assignment', function (): void {
    $student = Student::factory()->for($this->family)->create();
    $hold = app(CreateCourseHold::class)->handle(
        $this->family,
        now()->addDays(2),
        [[
            'course_id' => $this->course->id,
            'quantity' => 2,
            'student_ids' => [$student->id, null],
        ]],
    );

    app(AddCourseHoldToCart::class)->handle($this->family, $hold, [$this->course->id => 1]);
    $order = app(CreateOrder::class)->handle($this->family);

    expect(app(CompleteOrder::class)->handle($order))->toBeTrue();

    $enrollment = Enrollment::query()->where('order_item_id', $order->orderItems->sole()->id)->sole();

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($enrollment->student_id)->toBe($student->id)
        ->and($enrollment->course_hold_seat_id)->not->toBeNull()
        ->and($hold->availableSeatCount())->toBe(1)
        ->and($this->course->getAvailableCapacity())->toBe(1);
});

it('releases claimed seats when a held checkout is cancelled', function (): void {
    $hold = app(CreateCourseHold::class)->handle(
        $this->family,
        now()->addDays(2),
        [['course_id' => $this->course->id, 'quantity' => 1]],
    );

    app(AddCourseHoldToCart::class)->handle($this->family, $hold);
    $order = app(CreateOrder::class)->handle($this->family);
    $seat = $hold->seats->sole()->refresh();

    expect($seat->claimed_order_item_id)->not->toBeNull()
        ->and(app(CancelOrder::class)->handle($order))->toBeTrue()
        ->and($seat->refresh()->claimed_order_item_id)->toBeNull()
        ->and($hold->availableSeatCount())->toBe(1);
});

it('cancels an abandoned held checkout and restores its claim', function (): void {
    $hold = app(CreateCourseHold::class)->handle(
        $this->family,
        now()->addDays(2),
        [['course_id' => $this->course->id, 'quantity' => 1]],
    );

    app(AddCourseHoldToCart::class)->handle($this->family, $hold);
    $order = app(CreateOrder::class)->handle($this->family);
    $order->update(['hold_checkout_expires_at' => now()->subMinute()]);

    $this->artisan('course-holds:cancel-expired-checkouts')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($hold->seats->sole()->refresh()->claimed_order_item_id)->toBeNull()
        ->and($hold->availableSeatCount())->toBe(1);
});

it('sends one reminder for holds approaching expiration', function (): void {
    $hold = CourseHold::factory()->create([
        'user_id' => $this->family->id,
        'created_at' => now()->subDays(2),
        'expires_at' => now()->addHours(12),
    ]);
    CourseHoldSeat::factory()->create([
        'course_hold_id' => $hold->id,
        'course_id' => $this->course->id,
        'locked_unit_price' => 12_000,
    ]);

    $this->artisan('course-holds:send-reminders')->assertSuccessful();
    $this->artisan('course-holds:send-reminders')->assertSuccessful();

    expect($hold->refresh()->reminder_sent_at)->not->toBeNull();

    Mail::assertQueued(
        ManagedMail::class,
        fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'course-hold-expiring'
            && $mail->hasTo($this->family->email),
    );
});

it('registers the expired class hold managed email', function (): void {
    $definition = app(EmailTypeRegistry::class)->get('course-hold-expired');
    $rendered = app(MailManager::class)->render('course-hold-expired', [
        'user.first_name' => 'Jamie',
        'course_hold.expires_at' => 'August 5, 2026 at 5:00 PM',
    ]);

    expect($definition->name('en'))->toBe('Your EAC Dance Class Hold Has Expired')
        ->and($definition->subject('en'))->toBe('Your EAC Dance Class Hold Has Expired')
        ->and($definition->body('en'))->toContain('{{ course_hold.expires_at }}')
        ->and($rendered->subject)->toBe('Your EAC Dance Class Hold Has Expired')
        ->and($rendered->html)->toContain('Hello Jamie')
        ->toContain('August 5, 2026 at 5:00 PM');
});

it('sends one email when a class hold expires with unpurchased seats', function (): void {
    $hold = CourseHold::factory()->create([
        'user_id' => $this->family->id,
        'expires_at' => now()->subMinute(),
    ]);
    CourseHoldSeat::factory()->create([
        'course_hold_id' => $hold->id,
        'course_id' => $this->course->id,
        'locked_unit_price' => 12_000,
    ]);

    $this->artisan('course-holds:send-expired-emails')->assertSuccessful();
    $this->artisan('course-holds:send-expired-emails')->assertSuccessful();

    expect($hold->refresh()->expired_email_sent_at)->not->toBeNull();

    Mail::assertQueued(
        ManagedMail::class,
        fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'course-hold-expired'
            && $mail->hasTo($this->family->email),
    );
    Mail::assertQueuedCount(1);
});

it('allows a reactivated hold to send another expiration email', function (): void {
    $hold = CourseHold::factory()->create([
        'user_id' => $this->family->id,
        'expires_at' => now()->subMinute(),
        'expired_email_sent_at' => now(),
    ]);
    CourseHoldSeat::factory()->create([
        'course_hold_id' => $hold->id,
        'course_id' => $this->course->id,
        'locked_unit_price' => 12_000,
    ]);

    app(UpdateCourseHold::class)->handle($hold, now()->addDay());

    expect($hold->refresh()->expired_email_sent_at)->toBeNull();
});
