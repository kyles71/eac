<?php

declare(strict_types=1);

use App\Actions\Store\CreatePaymentPlan;
use App\Enums\FormTypes;
use App\Enums\OrderStatus;
use App\Filament\User\Pages\CheckoutSuccess;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\ProductQuestionAnswer;
use App\Models\Student;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
});

it('shows completed orders as confirmed', function () {
    $product = Product::factory()->create(['price' => 5000]);
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_completed',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->assertOk()
        ->assertSee('Completed')
        ->assertDontSee('Payment Finalizing');
});

it('shows saved purchaser answers on the order confirmation', function (): void {
    $product = Product::factory()->create(['name' => 'Competition Shirt']);
    $order = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);
    ProductQuestionAnswer::factory()->create([
        'order_item_id' => $orderItem->id,
        'product_question_id' => null,
        'question' => 'Dancer name',
        'answer' => 'Avery Stone',
    ]);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->assertSee('Answers for Competition Shirt')
        ->assertSee('Dancer name')
        ->assertSee('Avery Stone');
});

it('shows order-specific course assignment fields for course purchases', function () {
    $course = Course::factory()->create(['name' => 'Ballet Basics']);
    $product = Product::factory()->forCourse($course)->create(['price' => 5000]);
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 5000,
        'total' => 5000,
    ]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => auth()->id(),
        'order_item_id' => $orderItem->id,
        'student_id' => null,
    ]);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->assertOk()
        ->assertSee('Next Step')
        ->assertSee('Thanks for enrolling in a course')
        ->assertSee('Ballet Basics');
});

it('does not show course assignment for non-course purchases', function () {
    $product = Product::factory()->standalone()->create(['price' => 5000]);
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->assertOk()
        ->assertDontSee('Thanks for enrolling in a course');
});

it('places course next steps beside order details with a responsive heading', function () {
    $course = Course::factory()->create();
    $product = Product::factory()->forCourse($course)->create(['price' => 5000]);
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 5000,
        'total' => 5000,
    ]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => auth()->id(),
        'order_item_id' => $orderItem->id,
        'student_id' => null,
    ]);

    $component = Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class);
    $schema = $component->instance()->content(Schema::make($component->instance()));
    /** @var Grid $orderLayout */
    $orderLayout = collect($schema->getComponents(withHidden: true))
        ->first(fn ($schemaComponent): bool => $schemaComponent instanceof Grid);
    [$orderDetails, $nextSteps] = $orderLayout->getChildSchema()->getComponents(withHidden: true);

    expect($orderLayout->getColumns('md'))->toBe(2)
        ->and($orderDetails)->toBeInstanceOf(Section::class)
        ->and($orderDetails->getColumnSpan('md'))->toBe(1)
        ->and((string) $orderDetails->getHeading())->toContain('md:hidden">Order Details - NEXT STEPS BELOW')
        ->and((string) $orderDetails->getHeading())->toContain('hidden md:inline">Order Details')
        ->and($orderDetails->getExtraAttributes()['class'])->toContain('h-full')
        ->and($nextSteps)->toBeInstanceOf(Grid::class)
        ->and($nextSteps->isVisible())->toBeTrue()
        ->and($nextSteps->getExtraAttributes()['class'])->toContain('[&>.fi-sc]:h-full');

    $nextStepSections = $nextSteps->getChildSchema()->getComponents(withHidden: true);

    expect(array_map(
        fn (Section $section): string => (string) $section->getHeading(),
        $nextStepSections,
    ))->toBe(['Next Step', 'Required Forms'])
        ->and($nextStepSections[0]->getColumnSpan('default'))->toBe('full')
        ->and($nextStepSections[0]->getExtraAttributes()['class'])->toContain('[&>.fi-section]:h-full')
        ->and($nextStepSections[1]->getColumnSpan('default'))->toBe('full');
});

it('keeps order details full width when the order has no course enrollment', function () {
    $product = Product::factory()->standalone()->create(['price' => 5000]);
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    $component = Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class);
    $schema = $component->instance()->content(Schema::make($component->instance()));
    /** @var Grid $orderLayout */
    $orderLayout = collect($schema->getComponents(withHidden: true))
        ->first(fn ($schemaComponent): bool => $schemaComponent instanceof Grid);
    [$orderDetails, $nextSteps] = $orderLayout->getChildSchema()->getComponents(withHidden: true);

    expect($orderDetails)->toBeInstanceOf(Section::class)
        ->and($orderDetails->getColumnSpan('md'))->toBe('full')
        ->and($orderDetails->getHeading())->toBe('Order Details')
        ->and($nextSteps)->toBeInstanceOf(Grid::class)
        ->and($nextSteps->isVisible())->toBeFalse();
});

it('assigns course enrollments from the confirmation page and shows required forms', function () {
    $student = Student::factory()->create(['user_id' => auth()->id(), 'first_name' => 'Avery']);
    $form = Form::factory()->create([
        'name' => 'Student Waiver',
        'form_type' => FormTypes::StudentWaiver,
    ]);
    $course = Course::factory()->create(['name' => 'Tap Basics']);
    $course->forms()->attach($form);
    $product = Product::factory()->forCourse($course)->create(['price' => 5000]);
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 5000,
        'total' => 5000,
    ]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);
    $enrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => auth()->id(),
        'order_item_id' => $orderItem->id,
        'student_id' => null,
    ]);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->set("assignmentStudentIds.{$enrollment->id}", $student->id)
        ->callAction('saveCourseAssignments')
        ->assertNotified('Course enrollment updated')
        ->assertSee('Required Forms')
        ->assertSee('Complete Student Waiver for Avery')
        ->assertDontSee('Thanks for enrolling in a course');

    expect($enrollment->refresh()->student_id)->toBe($student->id);

    expect(FormUser::query()
        ->where('form_id', $form->id)
        ->where('student_id', $student->id)
        ->pending()
        ->exists())->toBeTrue();
});

it('only shows pending forms required by courses in the current order', function () {
    $student = Student::factory()->create(['user_id' => auth()->id(), 'first_name' => 'Avery']);
    $orderForm = Form::factory()->create([
        'name' => 'Current Course Waiver',
        'form_type' => FormTypes::StudentWaiver,
    ]);
    $unrelatedForm = Form::factory()->create([
        'name' => 'Other Course Form',
        'form_type' => FormTypes::ShowcaseParticipation,
    ]);
    $course = Course::factory()->create(['name' => 'Tap Basics']);
    $otherCourse = Course::factory()->create(['name' => 'Jazz Basics']);
    $course->forms()->attach($orderForm);
    $otherCourse->forms()->attach($unrelatedForm);
    $product = Product::factory()->forCourse($course)->create(['price' => 5000]);
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 5000,
        'total' => 5000,
    ]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $otherCourse->id,
        'user_id' => auth()->id(),
        'order_item_id' => null,
    ]);
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => auth()->id(),
        'order_item_id' => $orderItem->id,
    ]);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->assertOk()
        ->assertSee('Complete Current Course Waiver for Avery')
        ->assertDontSee('Other Course Form');
});

it('does not cross apply one order enrollment course requirements to another order student', function () {
    $avery = Student::factory()->create(['user_id' => auth()->id(), 'first_name' => 'Avery']);
    $blake = Student::factory()->create(['user_id' => auth()->id(), 'first_name' => 'Blake']);
    $waiver = Form::factory()->create([
        'name' => 'Student Waiver',
        'form_type' => FormTypes::StudentWaiver,
    ]);
    $showcase = Form::factory()->create([
        'name' => 'Showcase Participation',
        'form_type' => FormTypes::ShowcaseParticipation,
    ]);
    $ballet = Course::factory()->create(['name' => 'Ballet 4']);
    $tap = Course::factory()->create(['name' => 'Tap 3']);
    $ballet->forms()->attach($waiver);
    $tap->forms()->attach($showcase);
    $balletProduct = Product::factory()->forCourse($ballet)->create(['price' => 5000]);
    $tapProduct = Product::factory()->forCourse($tap)->create(['price' => 5000]);
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 10000,
        'total' => 10000,
    ]);
    $balletOrderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $balletProduct->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);
    $tapOrderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $tapProduct->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    Enrollment::factory()->withStudent($blake)->create([
        'course_id' => $ballet->id,
        'user_id' => auth()->id(),
        'order_item_id' => $balletOrderItem->id,
    ]);
    Enrollment::factory()->withStudent($avery)->create([
        'course_id' => $tap->id,
        'user_id' => auth()->id(),
        'order_item_id' => $tapOrderItem->id,
    ]);
    Enrollment::factory()->withStudent($avery)->create([
        'course_id' => $ballet->id,
        'user_id' => auth()->id(),
        'order_item_id' => null,
    ]);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->assertOk()
        ->assertSee('Complete Showcase Participation for Avery')
        ->assertSee('Complete Student Waiver for Blake')
        ->assertDontSee('Complete Student Waiver for Avery');
});

it('reassures users while a successful stripe redirect is finalizing', function () {
    $product = Product::factory()->create(['price' => 5000]);
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Processing,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_processing',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    Livewire::withQueryParams([
        'order_id' => $order->id,
        'payment_intent' => 'pi_processing',
        'redirect_status' => 'succeeded',
    ])
        ->test(CheckoutSuccess::class)
        ->assertOk()
        ->assertSee('Payment Finalizing')
        ->assertSee('Your payment was submitted successfully')
        ->assertSee('Processing');
});

it('clears purchased cart items on a successful stripe return', function () {
    $product = Product::factory()->create(['price' => 5000]);
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Processing,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_processing',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    Livewire::withQueryParams([
        'order_id' => $order->id,
        'payment_intent' => 'pi_processing',
        'redirect_status' => 'succeeded',
    ])
        ->test(CheckoutSuccess::class)
        ->assertOk();

    expect(CartItem::query()->where('user_id', auth()->id())->count())->toBe(0)
        ->and($order->refresh()->cart_items_cleared_at)->not->toBeNull();
});

it('does not clear cart items without a successful stripe return', function () {
    $product = Product::factory()->create(['price' => 5000]);
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Processing,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_processing',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->assertOk();

    expect($cartItem->refresh()->quantity)->toBe(1)
        ->and($order->refresh()->cart_items_cleared_at)->toBeNull();
});

it('does not clear cart items for a pending order', function () {
    $product = Product::factory()->create(['price' => 5000]);
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_pending',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    Livewire::withQueryParams([
        'order_id' => $order->id,
        'redirect_status' => 'succeeded',
    ])
        ->test(CheckoutSuccess::class)
        ->assertOk();

    expect($cartItem->refresh()->quantity)->toBe(1)
        ->and($order->refresh()->cart_items_cleared_at)->toBeNull();
});

it('reloads the order status while waiting for webhook completion', function () {
    $product = Product::factory()->create(['price' => 5000]);
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Processing,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_polling',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    $component = Livewire::withQueryParams([
        'order_id' => $order->id,
        'redirect_status' => 'succeeded',
    ])
        ->test(CheckoutSuccess::class)
        ->assertSee('Payment Finalizing');

    $order->update(['status' => OrderStatus::Completed]);

    $component
        ->call('refreshOrderStatus')
        ->assertSee('Completed')
        ->assertDontSee('Payment Finalizing');
});

it('shows actual paid amount and payment plan details for payment plan orders', function () {
    $product = Product::factory()->create(['price' => 10000]);
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
    ]);

    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 10000,
        'payment_plan_fee' => 300,
        'total' => 10300,
        'payment_plan_template_id' => $template->id,
        'stripe_payment_intent_id' => 'pi_plan',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 10000,
        'total_price' => 10000,
    ]);

    (new CreatePaymentPlan)->handle($order, $template);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->assertOk()
        ->assertSee('Total Paid')
        ->assertSee('$25.75')
        ->assertSee('Payment Plan Details')
        ->assertSee('4 Monthly payments')
        ->assertSee('$3.00')
        ->assertSee('$103.00')
        ->assertSee('$77.25');
});
