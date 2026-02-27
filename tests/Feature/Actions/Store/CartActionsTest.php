<?php

declare(strict_types=1);

use App\Actions\Store\AddToCart;
use App\Actions\Store\RemoveFromCart;
use App\Actions\Store\UpdateCartQuantity;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Product;
use App\Models\User;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->course = Course::factory()->create(['capacity' => 5]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
});

it('can add a product to the cart', function () {
    $action = new AddToCart;
    $cartItem = $action->handle($this->user, $this->product);

    expect($cartItem)->toBeInstanceOf(CartItem::class);

    assertDatabaseHas(CartItem::class, [
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);
});

it('increments quantity when adding the same product again', function () {
    $action = new AddToCart;
    $action->handle($this->user, $this->product);
    $action->handle($this->user, $this->product);

    expect(CartItem::query()->where('user_id', $this->user->id)->count())->toBe(1);

    $cartItem = CartItem::query()
        ->where('user_id', $this->user->id)
        ->where('product_id', $this->product->id)
        ->first();

    expect($cartItem->quantity)->toBe(2);
});

it('rejects adding to cart when course is at capacity', function () {
    // Fill all spots
    for ($i = 0; $i < 5; $i++) {
        Enrollment::factory()->create(['course_id' => $this->course->id]);
    }

    $action = new AddToCart;
    $action->handle($this->user, $this->product);
})->throws(InvalidArgumentException::class, 'Only 0 spot(s) remaining for this course.');

it('rejects adding to cart when quantity exceeds available capacity', function () {
    // Fill 4 of 5 spots
    for ($i = 0; $i < 4; $i++) {
        Enrollment::factory()->create(['course_id' => $this->course->id]);
    }

    $action = new AddToCart;
    $action->handle($this->user, $this->product);
    $action->handle($this->user, $this->product); // This should fail - 1 available but already have 1 in cart
})->throws(InvalidArgumentException::class, 'Only 1 spot(s) remaining for this course.');

it('rejects adding an inactive product to the cart', function () {
    $this->product->update(['is_active' => false]);

    $action = new AddToCart;
    $action->handle($this->user, $this->product->refresh());
})->throws(InvalidArgumentException::class, 'This product is not available for purchase.');

it('rejects adding a product with no price', function () {
    $this->product->update(['price' => 0]);

    $action = new AddToCart;
    $action->handle($this->user, $this->product->refresh());
})->throws(InvalidArgumentException::class, 'This product does not have a valid price.');

it('can remove an item from the cart', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);

    $action = new RemoveFromCart;
    $action->handle($this->user, $cartItem->id);

    assertDatabaseMissing(CartItem::class, [
        'id' => $cartItem->id,
    ]);
});

it('rejects removing a cart item that does not belong to the user', function () {
    $otherUser = User::factory()->create();
    $cartItem = CartItem::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $this->product->id,
    ]);

    $action = new RemoveFromCart;
    $action->handle($this->user, $cartItem->id);
})->throws(InvalidArgumentException::class, 'Cart item not found.');

it('can update cart item quantity', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $action = new UpdateCartQuantity;
    $updated = $action->handle($this->user, $cartItem->id, 3);

    expect($updated->quantity)->toBe(3);
});

it('rejects updating quantity beyond course capacity', function () {
    // 3 spots already taken
    for ($i = 0; $i < 3; $i++) {
        Enrollment::factory()->create(['course_id' => $this->course->id]);
    }

    $cartItem = CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $action = new UpdateCartQuantity;
    $action->handle($this->user, $cartItem->id, 5); // Only 2 available
})->throws(InvalidArgumentException::class, 'Only 2 spot(s) remaining for this course.');

it('rejects updating quantity to less than 1', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);

    $action = new UpdateCartQuantity;
    $action->handle($this->user, $cartItem->id, 0);
})->throws(InvalidArgumentException::class, 'Quantity must be at least 1.');
