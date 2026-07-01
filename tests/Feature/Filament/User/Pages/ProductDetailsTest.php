<?php

declare(strict_types=1);

use App\Filament\User\Pages\ProductDetails;
use App\Filament\User\Pages\Store;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use App\Models\User;
use App\Support\MediaDisks;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    Filament::setCurrentPanel('user');
    Storage::fake('public');
    Storage::fake(MediaDisks::private());

    $this->course = Course::factory()->create([
        'capacity' => 5,
        'duration' => 60,
        'guest_teacher' => 'Misty Copeland',
        'start_time' => CarbonImmutable::parse('2026-06-01 18:30:00', 'UTC'),
    ]);

    $this->product = Product::factory()
        ->forCourse($this->course)
        ->create([
            'description' => 'A polished class for growing dancers.',
            'price' => 5000,
        ]);
});

it('can render an available product details page', function () {
    livewire(ProductDetails::class, ['product' => $this->product])
        ->assertOk()
        ->assertSee($this->product->name)
        ->assertSee('A polished class for growing dancers.')
        ->assertSee('$50.00');
});

it('shows storefront details for linked products', function () {
    livewire(ProductDetails::class, ['product' => $this->product])
        ->assertSee('Start Time')
        ->assertSee('Jun 1, 2026 11:30 AM')
        ->assertSee('Duration')
        ->assertSee('60 minutes')
        ->assertSee('Teacher')
        ->assertSee('Misty Copeland')
        ->assertSee('Available Spots')
        ->assertSee('5');
});

it('shows linked course staff profiles in tooltips', function () {
    $teacher = User::factory()->isTeacher()->create([
        'first_name' => 'Martha',
        'last_name' => 'Graham',
        'staff_bio' => 'Martha teaches modern dance.',
    ]);
    $teacher->addMedia(UploadedFile::fake()->image('martha-staff.jpg'))
        ->toMediaCollection('staff-photo');

    $this->course->update(['guest_teacher' => null]);
    $this->course->teachers()->sync([$teacher->id]);

    livewire(ProductDetails::class, ['product' => $this->product->refresh()])
        ->assertSee('Martha Graham')
        ->assertSee('Martha teaches modern dance.')
        ->assertSee('martha-staff.jpg')
        ->assertSee('x-tooltip', escape: false);
});

it('renders product and linked item gallery images', function () {
    $this->product->addMedia(UploadedFile::fake()->image('product-gallery.jpg'))
        ->toMediaCollection('images');

    $this->course->addMedia(UploadedFile::fake()->image('course-gallery.jpg'))
        ->toMediaCollection('images');

    $this->product->update(['include_productable_images' => true]);

    livewire(ProductDetails::class, ['product' => $this->product->refresh()])
        ->assertSee('product-gallery.jpg')
        ->assertSee('course-gallery.jpg');
});

it('can add one item to the cart', function () {
    livewire(ProductDetails::class, ['product' => $this->product])
        ->callAction('addToCart')
        ->assertNotified('Added to cart');

    expect(CartItem::query()
        ->where('user_id', auth()->id())
        ->where('product_id', $this->product->id)
        ->value('quantity'))->toBe(1);
});

it('disables adding to cart when capacity is sold out', function () {
    Enrollment::factory()
        ->count(5)
        ->create(['course_id' => $this->course->id]);

    livewire(ProductDetails::class, ['product' => $this->product->refresh()])
        ->assertActionDisabled('addToCart')
        ->assertSee('Sold Out');
});

it('redirects inactive products back to the store', function () {
    $product = Product::factory()->inactive()->create(['price' => 5000]);

    $this->get(ProductDetails::getUrl(['product' => $product], false))
        ->assertRedirect(Store::getUrl());
});

it('redirects products without a valid price back to the store', function () {
    $product = Product::factory()->create(['price' => 0]);

    $this->get(ProductDetails::getUrl(['product' => $product], false))
        ->assertRedirect(Store::getUrl());
});

it('redirects products outside their availability window back to the store', function () {
    $scheduledProduct = Product::factory()->availableFrom(now()->addDay())->create(['price' => 5000]);
    $expiredProduct = Product::factory()->availableUntil(now()->subMinute())->create(['price' => 5000]);

    $this->get(ProductDetails::getUrl(['product' => $scheduledProduct], false))
        ->assertRedirect(Store::getUrl());

    $this->get(ProductDetails::getUrl(['product' => $expiredProduct], false))
        ->assertRedirect(Store::getUrl());
});

it('renders early access product details for directly granted users', function () {
    $product = Product::factory()
        ->availableFrom(now()->addDay())
        ->create([
            'description' => 'Early registration window.',
            'price' => 5000,
        ]);

    ProductEarlyAccessWindow::factory()
        ->for($product)
        ->create()
        ->users()
        ->attach(auth()->user());

    livewire(ProductDetails::class, ['product' => $product])
        ->assertOk()
        ->assertSee('Early registration window.')
        ->assertSee('$50.00');
});

it('redirects enrollment restricted products back to the store', function () {
    $requiredCourse = Course::factory()->create();
    $product = Product::factory()->create([
        'price' => 5000,
        'requires_course_id' => $requiredCourse->id,
    ]);

    $this->get(ProductDetails::getUrl(['product' => $product], false))
        ->assertRedirect(Store::getUrl());
});
