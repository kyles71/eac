<?php

declare(strict_types=1);

use App\Filament\User\Pages\Cart;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\DiscountCode;
use App\Models\GiftCard;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

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
        ->set('promoCode', $discountCode->code)
        ->call('applyPromoCode')
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
        ->set('promoCode', 'INVALID_CODE')
        ->call('applyPromoCode')
        ->assertSet('appliedDiscountCodeId', null)
        ->assertNotified('Invalid promo code');
});

it('can remove an applied discount', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->percentage(20)->create();

    livewire(Cart::class)
        ->set('promoCode', $discountCode->code)
        ->call('applyPromoCode')
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
        ->set('giftCardCode', $giftCard->code)
        ->call('redeemGiftCard')
        ->assertNotified('Gift card redeemed!');

    expect($user->refresh()->credit_balance)->toBe(5000);
});

it('shows error for invalid gift card', function () {
    livewire(Cart::class)
        ->set('giftCardCode', 'INVALID_CODE')
        ->call('redeemGiftCard')
        ->assertNotified('Invalid gift card');
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
        ->assertSee('Payment Option')
        ->assertSee('Payment Plan');
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
        ->set('selectedPaymentPlanTemplateId', $template->id)
        ->assertSee('4 payments of')
        ->assertSee('Amount Due Today');
});

it('calculates discount correctly in grand total', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(2000)->create();

    $component = livewire(Cart::class)
        ->set('promoCode', $discountCode->code)
        ->call('applyPromoCode');

    // Subtotal = 2 x 5000 = 10000, Discount = 2000, Grand Total = 8000
    expect($component->get('subtotal'))->toBe(10000);
    expect($component->get('discountAmount'))->toBe(2000);
    expect($component->get('grandTotal'))->toBe(8000);
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
