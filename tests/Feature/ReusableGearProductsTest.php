<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Gear;
use App\Models\GiftCardType;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

it('allows multiple Product listings to belong to the same Gear', function () {
    $gear = Gear::factory()->create();

    $firstProduct = Product::factory()->forGear($gear)->create(['name' => 'Fall Jacket']);
    $secondProduct = Product::factory()->forGear($gear)->create(['name' => 'Spring Jacket']);

    expect($gear->products()->pluck('products.id')->all())
        ->toBe([$firstProduct->id, $secondProduct->id]);
});

it('continues to enforce one Product for singular productables', function (string $productableType) {
    $productable = $productableType::factory()->create();

    Product::factory()->create([
        'productable_type' => $productableType,
        'productable_id' => $productable->id,
    ]);

    expect(fn () => Product::factory()->create([
        'productable_type' => $productableType,
        'productable_id' => $productable->id,
    ]))->toThrow(ValidationException::class, 'already has a Product')
        ->and(Product::query()
            ->where('productable_type', $productableType)
            ->where('productable_id', $productable->id)
            ->count())->toBe(1);
})->with([
    'Course' => [Course::class],
    'Gift Card Type' => [GiftCardType::class],
]);

it('allows an existing singular Product to be updated', function () {
    $product = Product::factory()->forCourse()->create();

    $product->update(['name' => 'Updated Course Listing']);

    expect($product->refresh()->name)->toBe('Updated Course Listing');
});

it('snapshots the Product name when an OrderItem is created', function () {
    $product = Product::factory()->forGear()->create(['name' => 'Original Listing Name']);
    $orderItem = OrderItem::factory()->for($product)->create();

    $product->update(['name' => 'Renamed Listing']);

    expect($orderItem->refresh()->product_name)->toBe('Original Listing Name');
});

it('backfills existing OrderItem Product names when the migration runs', function () {
    $product = Product::factory()->forGear()->create(['name' => 'Historical Listing']);
    $orderItem = OrderItem::factory()->for($product)->create();
    $migration = require database_path('migrations/2026_08_20_155942_enable_reusable_gear_and_snapshot_order_item_product_names.php');

    $migration->down();

    expect(Schema::hasColumn('order_items', 'product_name'))->toBeFalse();

    $migration->up();

    expect(DB::table('order_items')->where('id', $orderItem->id)->value('product_name'))
        ->toBe('Historical Listing');
});
