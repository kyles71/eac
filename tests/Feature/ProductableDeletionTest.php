<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Courses\Pages\ListCourses;
use App\Filament\Admin\Resources\Gear\Pages\ListGear;
use App\Filament\Admin\Resources\GiftCardTypes\Pages\ListGiftCardTypes;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Models\Course;
use App\Models\Gear;
use App\Models\GiftCardType;
use App\Models\OrderItem;
use App\Models\Product;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('allows deleting an item without a linked product', function (string $productableType) {
    $productable = $productableType::factory()->create();

    expect(auth()->user()->can('delete', $productable))->toBeTrue();
})->with('productable types');

it('prevents deleting an item linked to an active product', function (string $productableType) {
    $productable = $productableType::factory()->create();

    Product::factory()->create([
        'is_active' => true,
        'productable_type' => $productableType,
        'productable_id' => $productable->id,
    ]);

    expect(auth()->user()->can('delete', $productable))->toBeFalse()
        ->and($productable->delete())->toBeFalse();
})->with('productable types');

it('prevents deleting an item linked to a product with sales', function (string $productableType) {
    $productable = $productableType::factory()->create();
    $product = Product::factory()->create([
        'is_active' => false,
        'productable_type' => $productableType,
        'productable_id' => $productable->id,
    ]);

    OrderItem::factory()->for($product)->create();

    expect(auth()->user()->can('delete', $productable))->toBeFalse()
        ->and($productable->delete())->toBeFalse();
})->with('productable types');

it('deletes an inactive unsold linked product with its item', function (string $productableType) {
    $productable = $productableType::factory()->create();
    $product = Product::factory()->create([
        'is_active' => false,
        'productable_type' => $productableType,
        'productable_id' => $productable->id,
    ]);

    expect(auth()->user()->can('delete', $productable))->toBeTrue()
        ->and($productable->delete())->toBeTrue();

    assertDatabaseMissing($productableType, ['id' => $productable->id]);
    assertDatabaseMissing(Product::class, ['id' => $product->id]);
})->with('productable types');

it('warns that deleting a linked item also deletes its product', function (string $productableType, string $listPage) {
    $productable = $productableType::factory()->create();

    Product::factory()->create([
        'is_active' => false,
        'productable_type' => $productableType,
        'productable_id' => $productable->id,
    ]);

    $component = livewire($listPage)
        ->loadTable();

    if ($listPage === ListCourses::class) {
        $component->set('activeTab', 'all');
    }

    $component
        ->mountAction(TestAction::make('delete')->table($productable))
        ->assertMountedActionModalSee(
            'This item has a linked product. Deleting it will also permanently delete the linked product.',
        );
})->with('productable resources');

it('warns that bulk deleting items also deletes linked products', function () {
    $gear = Gear::factory()->create();

    livewire(ListGear::class)
        ->loadTable()
        ->selectTableRecords([$gear])
        ->mountAction(TestAction::make('delete')->table()->bulk())
        ->assertMountedActionModalSee(
            'Any selected items with linked products will also permanently delete those products.',
        );
});

it('only bulk deletes items whose linked products can be deleted', function (string $productableType, string $listPage) {
    $deletableProductable = $productableType::factory()->create();
    $blockedProductable = $productableType::factory()->create();
    $deletableProduct = Product::factory()->create([
        'is_active' => false,
        'productable_type' => $productableType,
        'productable_id' => $deletableProductable->id,
    ]);
    $blockedProduct = Product::factory()->create([
        'is_active' => true,
        'productable_type' => $productableType,
        'productable_id' => $blockedProductable->id,
    ]);

    $component = livewire($listPage)
        ->loadTable();

    if ($listPage === ListCourses::class) {
        $component->set('activeTab', 'all');
    }

    $component
        ->selectTableRecords([$deletableProductable, $blockedProductable])
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertNotified();

    assertDatabaseMissing($productableType, ['id' => $deletableProductable->id]);
    assertDatabaseMissing(Product::class, ['id' => $deletableProduct->id]);
    assertDatabaseHas($productableType, ['id' => $blockedProductable->id]);
    assertDatabaseHas(Product::class, ['id' => $blockedProduct->id]);
})->with('productable resources');

it('only bulk deletes inactive products without sales', function () {
    $deletableProduct = Product::factory()->create(['is_active' => false]);
    $activeProduct = Product::factory()->create(['is_active' => true]);
    $soldProduct = Product::factory()->create(['is_active' => false]);

    OrderItem::factory()->for($soldProduct)->create();

    livewire(ListProducts::class)
        ->loadTable()
        ->selectTableRecords([$deletableProduct, $activeProduct, $soldProduct])
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertNotified();

    assertDatabaseMissing(Product::class, ['id' => $deletableProduct->id]);
    assertDatabaseHas(Product::class, ['id' => $activeProduct->id]);
    assertDatabaseHas(Product::class, ['id' => $soldProduct->id]);
});

dataset('productable types', [
    Course::class,
    GiftCardType::class,
    Gear::class,
]);

dataset('productable resources', [
    [Course::class, ListCourses::class],
    [GiftCardType::class, ListGiftCardTypes::class],
    [Gear::class, ListGear::class],
]);
