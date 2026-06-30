<?php

declare(strict_types=1);

use App\Enums\ManagedBannerRenderLocation;
use App\Enums\StoreView;
use App\Filament\User\Pages\ProductDetails;
use App\Filament\User\Pages\Store;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ManagedBanner;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use App\Services\UserBannerRenderHookRegistrarService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
    Storage::fake('public');
    $this->course = Course::factory()->create(['capacity' => 5]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
});

it('can render the store page', function () {
    livewire(Store::class)
        ->assertOk();
});

it('keeps scoped managed banners visible after the store table loads', function (): void {
    app(UserBannerRenderHookRegistrarService::class)->registerManagedBanners();

    ManagedBanner::factory()
        ->forScope(Store::class)
        ->create([
            'render_location' => ManagedBannerRenderLocation::PageStart,
            'title' => 'Store table notice',
        ]);

    livewire(Store::class)
        ->assertSee('Store table notice')
        ->loadTable()
        ->assertSee('Store table notice');
});

it('defaults to list view', function () {
    $component = livewire(Store::class);

    expect($component->instance()->storeView)->toBe(StoreView::List)
        ->and($component->instance()->getTable()->getContentGrid())->toBeNull()
        ->and(auth()->user()->refresh()->store_view)->toBe(StoreView::List);

    $component
        ->assertActionDisabled(TestAction::make('listView')->table())
        ->assertActionEnabled(TestAction::make('cardView')->table());
});

it('switches to card view and persists the preference', function () {
    $component = livewire(Store::class)
        ->callAction(TestAction::make('cardView')->table());

    expect($component->instance()->storeView)->toBe(StoreView::Cards)
        ->and($component->instance()->getTable()->getContentGrid())->toBe([
            'default' => 1,
            'md' => 2,
            'xl' => 3,
        ])
        ->and(auth()->user()->refresh()->store_view)->toBe(StoreView::Cards);

    livewire(Store::class)
        ->assertSet('storeView', StoreView::Cards)
        ->assertActionEnabled(TestAction::make('listView')->table())
        ->assertActionDisabled(TestAction::make('cardView')->table());
});

it('switches back to list view and persists the preference', function () {
    auth()->user()->update(['store_view' => StoreView::Cards]);

    $component = livewire(Store::class)
        ->callAction(TestAction::make('listView')->table());

    expect($component->instance()->storeView)->toBe(StoreView::List)
        ->and($component->instance()->getTable()->getContentGrid())->toBeNull()
        ->and(auth()->user()->refresh()->store_view)->toBe(StoreView::List);
});

it('shows the first storefront image in card view', function () {
    $this->product->addMedia(UploadedFile::fake()->image('product-card.jpg'))
        ->toMediaCollection('images');
    auth()->user()->update(['store_view' => StoreView::Cards]);

    livewire(Store::class)
        ->loadTable()
        ->assertSee('product-card.jpg');
});

it('shows a linked product image in card view when enabled', function () {
    $this->course->addMedia(UploadedFile::fake()->image('course-card.jpg'))
        ->toMediaCollection('images');
    $this->product->update(['include_productable_images' => true]);
    auth()->user()->update(['store_view' => StoreView::Cards]);

    livewire(Store::class)
        ->loadTable()
        ->assertSee('course-card.jpg');
});

it('shows an image placeholder in card view when no image is available', function () {
    auth()->user()->update(['store_view' => StoreView::Cards]);

    livewire(Store::class)
        ->loadTable()
        ->assertSee('product-placeholder.svg')
        ->assertSee("No image available for {$this->product->name}");
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

it('does not display products outside their availability window', function () {
    $scheduledProduct = Product::factory()->availableFrom(now()->addDay())->create(['price' => 5000]);
    $expiredProduct = Product::factory()->availableUntil(now()->subMinute())->create(['price' => 5000]);

    livewire(Store::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->product])
        ->assertCanNotSeeTableRecords([$scheduledProduct, $expiredProduct]);
});

it('displays early access products to directly granted users', function () {
    $earlyAccessProduct = Product::factory()
        ->availableFrom(now()->addDay())
        ->create(['price' => 5000]);

    ProductEarlyAccessWindow::factory()
        ->for($earlyAccessProduct)
        ->create()
        ->users()
        ->attach(auth()->user());

    livewire(Store::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->product, $earlyAccessProduct]);
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

it('does not search or sort by computed available spots', function () {
    livewire(Store::class)
        ->assertTableColumnExists(
            'available_spots',
            fn (TextColumn $column): bool => ! $column->isSearchable() && ! $column->isSortable(),
        );
});

it('shows the full description in a tooltip when the table value is truncated', function () {
    $description = 'This is a longer store description that should stay compact in the table but be visible in full on hover.';

    $this->product->update(['description' => $description]);

    livewire(Store::class)
        ->loadTable()
        ->assertTableColumnExists(
            'description',
            fn (TextColumn $column): bool => $column->getTooltip() === $description,
            $this->product,
        );
});

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

it('keeps product navigation and quick add available in card view', function () {
    auth()->user()->update(['store_view' => StoreView::Cards]);

    $component = livewire(Store::class);

    expect($component->instance()->getTable()->getRecordUrl($this->product))
        ->toBe(ProductDetails::getUrl(['product' => $this->product]));

    $component
        ->callAction(TestAction::make('addToCart')->table($this->product))
        ->assertNotified('Added to cart');

    expect(CartItem::query()
        ->where('user_id', auth()->id())
        ->where('product_id', $this->product->id)
        ->value('quantity'))->toBe(1);
});
