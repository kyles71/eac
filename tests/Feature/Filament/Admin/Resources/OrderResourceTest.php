<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Filament\Admin\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->product = Product::factory()->create(['price' => 5000]);
});

it('can render the orders index page', function () {
    livewire(ListOrders::class)
        ->assertOk();
});

it('can render the order view page', function () {
    $order = Order::factory()->completed()->create();

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
    ]);

    livewire(ViewOrder::class, [
        'record' => $order->id,
    ])
        ->assertOk();
});

it('can list orders', function () {
    $orders = Order::factory(3)->completed()->create();

    livewire(ListOrders::class)
        ->loadTable()
        ->assertCanSeeTableRecords($orders);
});

it('can filter orders by status', function () {
    $completedOrder = Order::factory()->completed()->create();
    $pendingOrder = Order::factory()->create(['status' => OrderStatus::Pending]);

    livewire(ListOrders::class)
        ->loadTable()
        ->filterTable('status', OrderStatus::Completed->value)
        ->assertCanSeeTableRecords([$completedOrder])
        ->assertCanNotSeeTableRecords([$pendingOrder]);
});

it('has required columns', function (string $column) {
    livewire(ListOrders::class)
        ->assertTableColumnExists($column);
})->with(['id', 'user.full_name', 'user.email', 'status', 'total', 'created_at']);

it('can search orders by customer name', function () {
    $user1 = User::factory()->create(['first_name' => 'John', 'last_name' => 'Doe']);
    $user2 = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Smith']);

    $order1 = Order::factory()->completed()->create(['user_id' => $user1->id]);
    $order2 = Order::factory()->completed()->create(['user_id' => $user2->id]);

    livewire(ListOrders::class)
        ->loadTable()
        ->searchTable('John')
        ->assertCanSeeTableRecords([$order1])
        ->assertCanNotSeeTableRecords([$order2]);
});
