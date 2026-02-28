<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Filament\User\Pages\Checkout;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Stripe\PaymentIntent;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
    $this->course = Course::factory()->create(['capacity' => 5]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
});

it('redirects to cart when cart is empty', function () {
    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    livewire(Checkout::class)
        ->assertRedirect();
});

it('creates a payment intent and displays the checkout page', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $mockPaymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_test_page',
        'client_secret' => 'pi_test_page_secret_xyz',
        'status' => 'requires_payment_method',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createPaymentIntent')
        ->once()
        ->andReturn($mockPaymentIntent);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $component = livewire(Checkout::class);
    $component->assertOk()
        ->assertSet('clientSecret', 'pi_test_page_secret_xyz')
        ->assertSet('currentStep', 1)
        ->assertSet('subtotal', 10000)
        ->assertSet('total', 10000)
        ->assertSet('checkoutAmount', 10000);
});

it('advances to confirmation step when payment method is set', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $mockPaymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_test_step',
        'client_secret' => 'pi_test_step_secret',
        'status' => 'requires_payment_method',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createPaymentIntent')
        ->once()
        ->andReturn($mockPaymentIntent);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    livewire(Checkout::class)
        ->assertSet('currentStep', 1)
        ->call('setPaymentMethod', 'pm_test_abc', 'visa', '4242')
        ->assertSet('currentStep', 2)
        ->assertSet('paymentMethodId', 'pm_test_abc')
        ->assertSet('cardBrand', 'visa')
        ->assertSet('cardLast4', '4242');
});

it('can go back to payment step from confirmation', function () {
    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $mockPaymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_test_back',
        'client_secret' => 'pi_test_back_secret',
        'status' => 'requires_payment_method',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createPaymentIntent')
        ->once()
        ->andReturn($mockPaymentIntent);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    livewire(Checkout::class)
        ->call('setPaymentMethod', 'pm_test_def', 'mastercard', '5555')
        ->assertSet('currentStep', 2)
        ->call('goBackToPayment')
        ->assertSet('currentStep', 1);
});
