<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Enums\CreditTransactionType;
use App\Enums\ProductType;
use App\Filament\User\Pages\Cart;
use App\Models\CartItem;
use App\Models\Costume;
use App\Models\Course;
use App\Models\CreditGrant;
use App\Models\DiscountCode;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\LegalDocumentVersion;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\User;
use App\Support\LegalDocuments\PaymentPlanTerms;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
    $this->course = Course::factory()->create(['capacity' => 5]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
});

it('can render the cart page', function () {
    livewire(Cart::class)
        ->assertOk();
});

it('displays cart items in the table for the authenticated user', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    livewire(Cart::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$cartItem]);
});

it('does not display other users cart items in the table', function () {
    $otherUser = User::factory()->create();

    $cartItem = CartItem::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $this->product->id,
    ]);

    livewire(Cart::class)
        ->assertCanNotSeeTableRecords([$cartItem]);
});

it('shows empty state when cart is empty', function () {
    livewire(Cart::class)
        ->loadTable()
        ->assertSee('Your cart is empty');
});

it('can increment item quantity via table action', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    livewire(Cart::class)
        ->callAction(TestAction::make('increment')->table($cartItem));

    expect($cartItem->refresh()->quantity)->toBe(2);
});

it('can decrement item quantity via table action', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 3,
    ]);

    livewire(Cart::class)
        ->callAction(TestAction::make('decrement')->table($cartItem));

    expect($cartItem->refresh()->quantity)->toBe(2);
});

it('does not decrement quantity below 1', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    livewire(Cart::class)
        ->call('decrementQuantity', $cartItem->id);

    expect($cartItem->refresh()->quantity)->toBe(1);
});

it('can remove an item from the cart via table action', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    livewire(Cart::class)
        ->callAction(TestAction::make('remove')->table($cartItem));

    expect(CartItem::query()->find($cartItem->id))->toBeNull();
});

it('can apply a valid promo code', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->percentage(20)->create();

    livewire(Cart::class)
        ->set('code', $discountCode->code)
        ->call('applyCode')
        ->assertSet('appliedDiscountCodeId', $discountCode->id)
        ->assertNotified('Discount applied');
});

it('shows error for invalid promo code', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    livewire(Cart::class)
        ->set('code', 'INVALID_CODE')
        ->call('applyCode')
        ->assertSet('appliedDiscountCodeId', null)
        ->assertNotified('Invalid code');
});

it('can remove an applied discount', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->percentage(20)->create();

    livewire(Cart::class)
        ->set('code', $discountCode->code)
        ->call('applyCode')
        ->assertSet('appliedDiscountCodeId', $discountCode->id)
        ->call('removeDiscount')
        ->assertSet('appliedDiscountCodeId', null)
        ->assertNotified('Discount removed');
});

it('can redeem a gift card', function () {
    $giftCard = GiftCard::factory()->amount(5000)->create();

    /** @var User $user */
    $user = auth()->user();
    $user->refresh();

    livewire(Cart::class)
        ->set('code', $giftCard->code)
        ->call('applyCode')
        ->assertNotified('Gift card redeemed!');

    expect($user->refresh()->credit_balance)->toBe(5000);
});

it('shows error for invalid gift card', function () {
    livewire(Cart::class)
        ->set('code', 'INVALID_CODE')
        ->call('applyCode')
        ->assertNotified('Invalid code');
});

it('shows payment plan options when templates exist', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
    ]);

    livewire(Cart::class)
        ->assertSet('selectedPaymentOption', Cart::PAYMENT_OPTION_PAY_IN_FULL)
        ->assertSee('Payment Option')
        ->assertSee('Pay In Full')
        ->assertSee('4 Monthly Payments');
});

it('does not allow a blank payment option placeholder', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    PaymentPlanTemplate::factory()->create();

    $component = livewire(Cart::class);
    $reflection = new ReflectionMethod(Cart::class, 'getOrderSummarySchema');
    $components = collect($reflection->invoke($component->instance()));

    $paymentOptionSelect = $components->first(
        fn (object $component): bool => method_exists($component, 'getName')
            && $component->getName() === 'selectedPaymentOption',
    );

    expect($paymentOptionSelect)->not->toBeNull()
        ->and($paymentOptionSelect->canSelectPlaceholder())->toBeFalse()
        ->and(array_key_first($paymentOptionSelect->getOptions()))->toBe(Cart::PAYMENT_OPTION_PAY_IN_FULL);
});

it('hides payment plan options when no active templates are eligible', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    PaymentPlanTemplate::factory()->inactive()->create([
        'min_price' => 1000,
        'max_price' => 10000,
    ]);

    PaymentPlanTemplate::factory()->create([
        'min_price' => 6000,
        'max_price' => 10000,
    ]);

    livewire(Cart::class)
        ->assertDontSee('Payment Option')
        ->assertDontSee('Pay In Full');
});

it('filters payment plan templates by product type and line total', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Any,
        'min_price' => 1000,
        'max_price' => 7000,
        'number_of_installments' => 3,
    ]);

    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Costume,
        'min_price' => 1000,
        'max_price' => 12000,
        'number_of_installments' => 4,
    ]);

    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Course,
        'min_price' => 1000,
        'max_price' => 12000,
        'number_of_installments' => 5,
    ]);

    livewire(Cart::class)
        ->assertDontSee('3 Monthly Payments')
        ->assertDontSee('4 Monthly Payments')
        ->assertSee('5 Monthly Payments');
});

it('only shows templates eligible for every item in a mixed cart', function () {
    $costume = Costume::factory()->create();
    $costumeProduct = Product::factory()->forCostume($costume)->create(['price' => 4000]);

    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $costumeProduct->id,
        'quantity' => 1,
    ]);

    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Course,
        'min_price' => 1000,
        'max_price' => 10000,
        'number_of_installments' => 3,
    ]);

    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Costume,
        'min_price' => 1000,
        'max_price' => 10000,
        'number_of_installments' => 4,
    ]);

    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Any,
        'min_price' => 1000,
        'max_price' => 10000,
        'number_of_installments' => 5,
    ]);

    livewire(Cart::class)
        ->assertDontSee('3 Monthly Payments')
        ->assertDontSee('4 Monthly Payments')
        ->assertSee('5 Monthly Payments');
});

it('filters course payment plan templates by semester', function () {
    $this->course->update(['semester' => CourseSemester::Fall]);

    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Course,
        'course_semesters' => [CourseSemester::Summer->value],
        'min_price' => 1000,
        'max_price' => 10000,
        'number_of_installments' => 3,
    ]);

    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Course,
        'course_semesters' => [CourseSemester::Fall->value],
        'min_price' => 1000,
        'max_price' => 10000,
        'number_of_installments' => 4,
    ]);

    livewire(Cart::class)
        ->assertDontSee('3 Monthly Payments')
        ->assertSee('4 Monthly Payments');
});

it('shows payment plan breakdown when a plan is selected', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
    ]);

    livewire(Cart::class)
        ->set('selectedPaymentOption', "template:{$template->id}")
        ->assertSet('paymentPlanFeeAmount', 300)
        ->assertSet('grandTotal', 10300)
        ->assertSee('4 payments of')
        ->assertSee('Payment Plan Fee (3%)')
        ->assertSee('$103.00')
        ->assertSee('Amount Due Today')
        ->assertSee('Proceed to Payment Plan Terms & Conditions')
        ->assertDontSee('Payment Plan Method');
});

it('initializes payment plan terms agreement when terms do not require scrolling', function () {
    publishPaymentPlanTermsForCartTest();

    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $template = PaymentPlanTemplate::factory()->create();

    [$termsSection, $termsEntry, $termsCheckbox] = checkoutTermsSchema($template);

    expect($termsSection->getHeading())->toBe('Payment Plan Terms & Conditions')
        ->and($termsSection->getExtraAttributes()['x-data'])->toContain('hasTerms: true')
        ->and($termsSection->getExtraAttributes()['x-data'])->toContain('element.clientHeight === 0')
        ->and($termsSection->getExtraAttributes()['x-data'])->toContain('element.scrollHeight <= element.clientHeight + 2')
        ->and((string) $termsEntry->getState())->toContain('new ResizeObserver')
        ->and((string) $termsEntry->getState())->toContain('@scroll="unlockTermsIfReadable($event.target)"')
        ->and($termsCheckbox->getExtraInputAttributes()['x-bind:disabled'])->toBe('!scrolledToBottom');
});

it('keeps payment plan terms agreement unavailable when no terms are published', function () {
    LegalDocumentVersion::query()->delete();

    try {
        CartItem::factory()->create([
            'user_id' => auth()->id(),
            'product_id' => $this->product->id,
            'quantity' => 1,
        ]);

        $template = PaymentPlanTemplate::factory()->create();

        [$termsSection, $termsEntry, $termsCheckbox] = checkoutTermsSchema($template);

        expect($termsSection->getHeading())->toBe('Payment Plan Terms & Conditions')
            ->and($termsSection->getExtraAttributes()['x-data'])->toContain('hasTerms: false')
            ->and((string) $termsEntry->getState())->toContain('Payment plan terms are not available.')
            ->and($termsCheckbox->getExtraInputAttributes()['x-bind:disabled'])->toBe('!scrolledToBottom');
    } finally {
        publishPaymentPlanTermsForCartTest();
    }
});

it('calculates discount correctly in grand total', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(2000)->create();

    $component = livewire(Cart::class)
        ->set('code', $discountCode->code)
        ->call('applyCode');

    // Subtotal = 2 x 5000 = 10000, Discount = 2000, Grand Total = 8000
    expect($component->get('subtotal'))->toBe(10000);
    expect($component->get('discountAmount'))->toBe(2000);
    expect($component->get('grandTotal'))->toBe(8000);
});

it('shows limited use credit in the order summary', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    CreditGrant::factory()
        ->for(auth()->user())
        ->amount(3000)
        ->restrictedTo(ProductType::Course)
        ->create();

    livewire(Cart::class)
        ->assertSet('restrictedCreditAmount', 3000)
        ->assertSet('grandTotal', 2000)
        ->assertSee('Limited Use Credit')
        ->assertSee('-$30.00')
        ->assertSee('$20.00');
});

it('shows limited use credit in the order summary after redeeming a restricted gift card', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->create();

    $giftCard = GiftCard::factory()
        ->forType($giftCardType)
        ->amount(3000)
        ->create();

    livewire(Cart::class)
        ->set('code', $giftCard->code)
        ->call('applyCode')
        ->assertNotified('Gift card redeemed!')
        ->assertSet('restrictedCreditAmount', 3000)
        ->assertSet('grandTotal', 2000)
        ->assertSee('Limited Use Credit')
        ->assertSee('-$30.00')
        ->assertSee('$20.00');
});

it('shows limited use credit reserved on a pending order in the order summary', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'subtotal' => 10000,
        'restricted_credit_applied' => 5000,
        'total' => 5000,
    ]);
    $grant = CreditGrant::factory()
        ->for(auth()->user())
        ->restrictedTo(ProductType::Course)
        ->create([
            'initial_amount' => 5000,
            'remaining_amount' => 0,
        ]);
    $grant->transactions()->create([
        'user_id' => auth()->id(),
        'amount' => -5000,
        'type' => CreditTransactionType::CheckoutDebit,
        'reference_type' => $order->getMorphClass(),
        'reference_id' => $order->id,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit_price' => 5000,
        'total_price' => 10000,
    ]);

    livewire(Cart::class)
        ->assertSet('restrictedCreditAmount', 5000)
        ->assertSet('grandTotal', 5000)
        ->assertSee('Limited Use Credit')
        ->assertSee('-$50.00')
        ->assertSee('$50.00');
});

it('shows applied store credit in the order summary', function () {
    CreditGrant::factory()->for(auth()->user())->amount(3000)->create();

    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    livewire(Cart::class)
        ->assertDontSee('Store Credit')
        ->set('useCredit', true)
        ->assertSet('creditAmount', 3000)
        ->assertSet('grandTotal', 2000)
        ->assertSee('Store Credit')
        ->assertSee('-$30.00')
        ->assertSee('$20.00');
});

it('shows store credit reserved on a pending order in the order summary after it is applied', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'subtotal' => 5000,
        'credit_applied' => 3000,
        'total' => 2000,
    ]);
    $grant = CreditGrant::factory()->for(auth()->user())->create([
        'initial_amount' => 3000,
        'remaining_amount' => 0,
    ]);
    $grant->transactions()->create([
        'user_id' => auth()->id(),
        'amount' => -3000,
        'type' => CreditTransactionType::CheckoutDebit,
        'reference_type' => $order->getMorphClass(),
        'reference_id' => $order->id,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    livewire(Cart::class)
        ->assertSee('Apply store credit ($30.00)')
        ->set('useCredit', true)
        ->assertSet('creditAmount', 3000)
        ->assertSet('grandTotal', 2000)
        ->assertSee('Store Credit')
        ->assertSee('-$30.00')
        ->assertSee('$20.00');
});

it('shows redeemed store credit in the order summary after it is applied', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $giftCard = GiftCard::factory()
        ->amount(3000)
        ->create();

    livewire(Cart::class)
        ->set('code', $giftCard->code)
        ->call('applyCode')
        ->assertNotified('Gift card redeemed!')
        ->assertSee('Apply store credit ($30.00)')
        ->set('useCredit', true)
        ->assertSet('creditAmount', 3000)
        ->assertSet('grandTotal', 2000)
        ->assertSee('Store Credit')
        ->assertSee('-$30.00')
        ->assertSee('$20.00');
});

it('refreshes totals and payment plan eligibility after quantity changes', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $template = PaymentPlanTemplate::factory()->create([
        'min_price' => 1000,
        'max_price' => 7000,
        'number_of_installments' => 3,
    ]);

    livewire(Cart::class)
        ->set('selectedPaymentOption', "template:{$template->id}")
        ->assertSet('subtotal', 5000)
        ->assertSet('paymentPlanFeeAmount', 150)
        ->assertSet('grandTotal', 5150)
        ->assertSee('3 Monthly Payments')
        ->call('incrementQuantity', $cartItem->id)
        ->assertSet('subtotal', 10000)
        ->assertSet('grandTotal', 10000)
        ->assertSet('selectedPaymentOption', Cart::PAYMENT_OPTION_PAY_IN_FULL)
        ->assertDontSee('3 Monthly Payments');
});

it('cannot modify other users cart items via increment', function () {
    $otherUser = User::factory()->create();

    $cartItem = CartItem::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    livewire(Cart::class)
        ->call('incrementQuantity', $cartItem->id);

    expect($cartItem->refresh()->quantity)->toBe(1);
});

it('cannot remove other users cart items', function () {
    $otherUser = User::factory()->create();

    $cartItem = CartItem::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    livewire(Cart::class)
        ->call('removeItem', $cartItem->id);

    expect(CartItem::query()->find($cartItem->id))->not->toBeNull();
});

function checkoutTermsSchema(PaymentPlanTemplate $template): array
{
    $component = livewire(Cart::class)
        ->set('selectedPaymentOption', "template:{$template->id}");

    $actionSchema = $component
        ->instance()
        ->checkoutAction()
        ->getSchema(Schema::make($component->instance()));

    $grid = $actionSchema->getComponents(withHidden: true)[0];
    $termsEntry = $grid->getChildSchema()->getComponents(withHidden: true)[0];
    $termsCheckbox = $grid->getChildSchema()->getComponents(withHidden: true)[1];

    return [$grid, $termsEntry, $termsCheckbox];
}

function publishPaymentPlanTermsForCartTest(): void
{
    PaymentPlanTerms::document()?->publishVersion(
        title: 'Payment Plan Terms & Conditions',
        content: '<p>Test payment plan terms.</p>',
    );
}
