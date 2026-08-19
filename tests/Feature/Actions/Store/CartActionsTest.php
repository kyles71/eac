<?php

declare(strict_types=1);

use App\Actions\Store\AddToCart;
use App\Actions\Store\RemoveFromCart;
use App\Actions\Store\UpdateCartQuantity;
use App\Models\CartItem;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use App\Models\ProductQuestion;
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

it('stores distinct purchaser answers when the same product is added repeatedly', function () {
    $question = ProductQuestion::factory()->for($this->product)->required()->create([
        'question' => 'Dancer name',
    ]);

    $action = new AddToCart;
    $action->handle($this->user, $this->product->refresh(), questionAnswers: [
        1 => ["question_{$question->id}" => 'Avery'],
    ]);
    $cartItem = $action->handle($this->user, $this->product->refresh(), questionAnswers: [
        1 => ["question_{$question->id}" => 'Jordan'],
    ]);

    expect($cartItem->quantity)->toBe(2)
        ->and($cartItem->storedQuestionAnswers())->toBe([
            1 => ["question_{$question->id}" => 'Avery'],
            2 => ["question_{$question->id}" => 'Jordan'],
        ]);
});

it('normalizes digit-only select answers when adding a product to the cart', function () {
    $question = ProductQuestion::factory()
        ->for($this->product)
        ->required()
        ->select(['4', '6', 'YXS'])
        ->create([
            'question' => 'Jacket size',
        ]);

    $cartItem = (new AddToCart)->handle(
        $this->user,
        $this->product->refresh(),
        questionAnswers: [
            1 => ["question_{$question->id}" => 6],
        ],
    );

    expect($cartItem->storedQuestionAnswers())->toBe([
        1 => ["question_{$question->id}" => '6'],
    ]);
});

it('requires configured purchaser answers when adding to cart', function () {
    ProductQuestion::factory()->for($this->product)->required()->create([
        'question' => 'Dancer name',
    ]);

    (new AddToCart)->handle($this->user, $this->product->refresh());
})->throws(InvalidArgumentException::class, 'Please answer "Dancer name"');

it('stores custom gift card amounts on separate cart lines', function () {
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create();

    $action = new AddToCart;
    $action->handle($this->user, $giftCardProduct, customGiftCardAmount: 7500);
    $action->handle($this->user, $giftCardProduct, customGiftCardAmount: 7500);
    $action->handle($this->user, $giftCardProduct, customGiftCardAmount: 2500);

    $cartItems = CartItem::query()
        ->where('user_id', $this->user->id)
        ->where('product_id', $giftCardProduct->id)
        ->orderBy('custom_gift_card_amount')
        ->get();

    expect($cartItems)->toHaveCount(2)
        ->and($cartItems[0]->custom_gift_card_amount)->toBe(2500)
        ->and($cartItems[0]->quantity)->toBe(1)
        ->and($cartItems[1]->custom_gift_card_amount)->toBe(7500)
        ->and($cartItems[1]->quantity)->toBe(2);
});

it('rejects custom gift card amounts below the configured minimum', function () {
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create();

    $action = new AddToCart;
    $action->handle($this->user, $giftCardProduct, customGiftCardAmount: 400);
})->throws(InvalidArgumentException::class, 'Gift card amount must be at least $5.00.');

it('rejects custom gift card amounts with cents', function () {
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount()
        ->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create();

    $action = new AddToCart;
    $action->handle($this->user, $giftCardProduct, customGiftCardAmount: 5050);
})->throws(InvalidArgumentException::class, 'Gift card amounts must be whole dollars.');

it('rejects custom amounts for fixed gift cards', function () {
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create();

    $action = new AddToCart;
    $action->handle($this->user, $giftCardProduct, customGiftCardAmount: 7500);
})->throws(InvalidArgumentException::class, 'Custom gift card amounts are only available for enabled gift cards.');

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

it('rejects adding a product outside its availability window', function () {
    $this->product->update(['available_from' => now()->addDay()]);

    $action = new AddToCart;
    $action->handle($this->user, $this->product->refresh());
})->throws(InvalidArgumentException::class, 'This product is not available yet.');

it('allows directly granted early access users to add a scheduled product', function () {
    $this->product->update(['available_from' => now()->addDay()]);

    ProductEarlyAccessWindow::factory()
        ->for($this->product)
        ->create()
        ->users()
        ->attach($this->user);

    $action = new AddToCart;
    $cartItem = $action->handle($this->user, $this->product->refresh());

    expect($cartItem->product_id)->toBe($this->product->id);
});

it('rejects adding a product that requires an unpurchased enrollment', function () {
    $requiredCourse = Course::factory()->create();
    $restrictedProduct = Product::factory()->create(['price' => 5000]);
    $restrictedProduct->requiredCourses()->attach($requiredCourse);

    $action = new AddToCart;
    $action->handle($this->user, $restrictedProduct);
})->throws(InvalidArgumentException::class, 'This product is limited to its configured purchase audience.');

it('rejects adding a product without required competition team membership', function () {
    $season = CompetitionSeason::factory()->current()->create();
    $requiredTeam = CompetitionTeam::factory()->for($season, 'season')->create();
    $restrictedProduct = Product::factory()->create(['price' => 5000]);
    $restrictedProduct->requiredCompetitionTeams()->attach($requiredTeam);

    $action = new AddToCart;
    $action->handle($this->user, $restrictedProduct);
})->throws(InvalidArgumentException::class, 'This product is limited to its configured purchase audience.');

it('allows a directly assigned user when another Product audience does not match', function () {
    $requiredCourse = Course::factory()->create();
    $restrictedProduct = Product::factory()->create(['price' => 5000]);
    $restrictedProduct->requiredCourses()->attach($requiredCourse);
    $restrictedProduct->assignedUsers()->attach($this->user);

    $cartItem = (new AddToCart)->handle($this->user, $restrictedProduct);

    expect($cartItem->product_id)->toBe($restrictedProduct->id);
});

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

it('appends answers when quantity increases and trims the final unit when it decreases', function () {
    $question = ProductQuestion::factory()->for($this->product)->required()->create([
        'question' => 'Dancer name',
    ]);
    $cartItem = CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'question_answers' => [
            1 => ["question_{$question->id}" => 'Avery'],
        ],
    ]);

    $action = new UpdateCartQuantity;
    $updated = $action->handle($this->user, $cartItem->id, 2, [
        1 => ["question_{$question->id}" => 'Jordan'],
    ]);

    expect($updated->storedQuestionAnswers())->toBe([
        1 => ["question_{$question->id}" => 'Avery'],
        2 => ["question_{$question->id}" => 'Jordan'],
    ]);

    $decremented = $action->handle($this->user, $cartItem->id, 1);

    expect($decremented->storedQuestionAnswers())->toBe([
        1 => ["question_{$question->id}" => 'Avery'],
    ]);
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

it('rejects updating a stale cart item whose product became unavailable', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);
    $this->product->update(['available_until' => now()->subMinute()]);

    $action = new UpdateCartQuantity;
    $action->handle($this->user, $cartItem->id, 2);
})->throws(InvalidArgumentException::class, 'This product is no longer available for purchase.');

it('rejects updating quantity to less than 1', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);

    $action = new UpdateCartQuantity;
    $action->handle($this->user, $cartItem->id, 0);
})->throws(InvalidArgumentException::class, 'Quantity must be at least 1.');
