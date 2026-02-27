<?php

declare(strict_types=1);

use App\Filament\User\Pages\Cart;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Product;
use App\Models\User;
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

it('displays cart items for the authenticated user', function () {
    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    livewire(Cart::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$cartItem]);
});

it('does not display other users cart items', function () {
    $otherUser = User::factory()->create();

    $otherCartItem = CartItem::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $this->product->id,
    ]);

    livewire(Cart::class)
        ->loadTable()
        ->assertCanNotSeeTableRecords([$otherCartItem]);
});

it('shows empty state when cart is empty', function () {
    livewire(Cart::class)
        ->loadTable()
        ->assertSee('Your cart is empty');
});

it('has required columns', function (string $column) {
    livewire(Cart::class)
        ->assertTableColumnExists($column);
})->with(['product.name', 'product.price', 'quantity', 'line_total']);
