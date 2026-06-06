<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Filament\Admin\Resources\Products\Pages\ViewProduct;
use App\Models\Costume;
use App\Models\Course;
use App\Models\GiftCardType;
use App\Models\Product;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;

use function Pest\Laravel\assertDatabaseHas;
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

it('has an include linked item images field on the product form', function () {
    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->assertSchemaComponentExists('include_productable_images')
        ->assertSchemaComponentStateSet('include_productable_images', false);
});

it('shows the include linked item images field after selecting a linked item', function () {
    $course = Course::factory()->create();

    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->assertSchemaComponentHidden('include_productable_images')
        ->fillForm([
            'productable_type' => Course::class,
        ])
        ->assertSchemaComponentHidden('include_productable_images')
        ->fillForm([
            'productable_id' => $course->id,
        ])
        ->assertSchemaComponentVisible('include_productable_images');
});

it('requires a linked item when a product type is selected', function () {
    livewire(ListProducts::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Course Product',
            'description' => null,
            'price' => '25.00',
            'is_active' => true,
            'productable_type' => Course::class,
            'productable_id' => null,
        ])
        ->assertHasActionErrors(['productable_id' => 'required']);
});

it('only offers linked items without an existing product', function (string $productableType) {
    $availableProductable = $productableType::factory()->create();
    $linkedProductable = $productableType::factory()->create();

    Product::factory()->create([
        'productable_type' => $productableType,
        'productable_id' => $linkedProductable->id,
    ]);

    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'productable_type' => $productableType,
        ])
        ->assertSchemaComponentExists(
            'productable_id',
            checkComponentUsing: fn (Select $select): bool => $select->getOptions() === [
                $availableProductable->id => $availableProductable->name,
            ],
        );
})->with([
    Course::class,
    GiftCardType::class,
    Costume::class,
]);

it('can create a linked product that includes linked item images', function () {
    $costume = Costume::factory()->create();

    livewire(ListProducts::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Costume Product',
            'description' => null,
            'price' => '42.50',
            'is_active' => true,
            'productable_type' => Costume::class,
            'productable_id' => $costume->id,
            'include_productable_images' => true,
        ])
        ->assertNotified();

    assertDatabaseHas(Product::class, [
        'name' => 'Costume Product',
        'price' => 4250,
        'productable_type' => Costume::class,
        'productable_id' => $costume->id,
        'include_productable_images' => true,
    ]);
});

it('formats product type labels', function () {
    $courseProduct = Product::factory()->forCourse()->create();
    $giftCardProduct = Product::factory()->forGiftCardType()->create();
    $costumeProduct = Product::factory()->forCostume()->create();
    $standaloneProduct = Product::factory()->standalone()->create();

    livewire(ListProducts::class)
        ->loadTable()
        ->assertTableColumnFormattedStateSet('productable_type', 'Course', $courseProduct)
        ->assertTableColumnFormattedStateSet('productable_type', 'Gift Card', $giftCardProduct)
        ->assertTableColumnFormattedStateSet('productable_type', 'Costume', $costumeProduct)
        ->assertTableColumnFormattedStateSet('productable_type', 'Generic Product', $standaloneProduct);
});

it('can search products by name', function () {
    $product1 = Product::factory()->create(['name' => 'Tap Dance 101']);
    $product2 = Product::factory()->create(['name' => 'Ballet Basics']);

    livewire(ListProducts::class)
        ->loadTable()
        ->searchTable('Tap Dance')
        ->assertCanSeeTableRecords([$product1])
        ->assertCanNotSeeTableRecords([$product2]);
});
