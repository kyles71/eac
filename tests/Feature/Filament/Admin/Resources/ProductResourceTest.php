<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Filament\Admin\Resources\Products\Pages\ViewProduct;
use App\Models\Product;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('can render the products index page', function () {
    livewire(ListProducts::class)
        ->assertOk();
});

it('can render the product view page', function () {
    $product = Product::factory()->create();

    livewire(ViewProduct::class, [
        'record' => $product->id,
    ])
        ->assertOk();
});

it('can list products', function () {
    $products = Product::factory(3)->create();

    livewire(ListProducts::class)
        ->loadTable()
        ->assertCanSeeTableRecords($products);
});

it('has required columns', function (string $column) {
    livewire(ListProducts::class)
        ->assertTableColumnExists($column);
})->with(['name', 'price', 'is_active', 'productable_type', 'created_at', 'updated_at']);

it('can search products by name', function () {
    $product1 = Product::factory()->create(['name' => 'Tap Dance 101']);
    $product2 = Product::factory()->create(['name' => 'Ballet Basics']);

    livewire(ListProducts::class)
        ->loadTable()
        ->searchTable('Tap Dance')
        ->assertCanSeeTableRecords([$product1])
        ->assertCanNotSeeTableRecords([$product2]);
});
