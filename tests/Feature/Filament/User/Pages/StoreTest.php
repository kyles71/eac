<?php

declare(strict_types=1);

use App\Filament\User\Pages\ProductDetails;
use App\Filament\User\Pages\Store;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Product;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
    $this->course = Course::factory()->create(['capacity' => 5]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
});

it('can render the store page', function () {
    livewire(Store::class)
        ->assertOk();
});

it('displays available products', function () {
    livewire(Store::class)
        ->loadTable()
        ->assertCanSeeTableRecords(Product::query()->available()->get());
});

it('does not display inactive products', function () {
    $inactiveProduct = Product::factory()->inactive()->create();

    livewire(Store::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->product])
        ->assertCanNotSeeTableRecords([$inactiveProduct]);
});

it('does not display products with zero price', function () {
    $freeProduct = Product::factory()->create(['price' => 0]);

    livewire(Store::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->product])
        ->assertCanNotSeeTableRecords([$freeProduct]);
});

it('does not display products that require an unpurchased enrollment', function () {
    $requiredCourse = Course::factory()->create();
    $restrictedProduct = Product::factory()->create([
        'requires_course_id' => $requiredCourse->id,
        'price' => 5000,
    ]);

    livewire(Store::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->product])
        ->assertCanNotSeeTableRecords([$restrictedProduct]);
});

it('displays products that require an already purchased enrollment', function () {
    $requiredCourse = Course::factory()->create();
    $restrictedProduct = Product::factory()->create([
        'requires_course_id' => $requiredCourse->id,
        'price' => 5000,
    ]);

    Enrollment::factory()->create([
        'course_id' => $requiredCourse->id,
        'user_id' => auth()->id(),
    ]);

    livewire(Store::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->product, $restrictedProduct]);
});

it('has required columns', function (string $column) {
    livewire(Store::class)
        ->assertTableColumnExists($column);
})->with(['name', 'description', 'price', 'available_spots']);

it('links table rows to product details', function () {
    $component = livewire(Store::class);

    expect($component->instance()->getTable()->getRecordUrl($this->product))
        ->toBe(ProductDetails::getUrl(['product' => $this->product]));
});

it('can still quickly add a product to the cart from the table', function () {
    livewire(Store::class)
        ->callAction(TestAction::make('addToCart')->table($this->product))
        ->assertNotified('Added to cart');

    expect(CartItem::query()
        ->where('user_id', auth()->id())
        ->where('product_id', $this->product->id)
        ->value('quantity'))->toBe(1);
});
