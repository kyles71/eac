<?php

declare(strict_types=1);

use App\Filament\User\Pages\ProductDetails;
use App\Filament\User\Pages\Store;
use App\Models\CartItem;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use App\Models\ProductQuestion;
use App\Models\Student;
use App\Models\User;
use App\Support\MediaDisks;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    Vite::partialMock()
        ->shouldReceive('asset')
        ->with('resources/js/filament/user/product-gallery.js')
        ->andReturn('/build/assets/product-gallery.js');

    Filament::setCurrentPanel('user');
    Storage::fake('public');
    Storage::fake(MediaDisks::private());

    $this->course = Course::factory()->create([
        'capacity' => 5,
        'guest_teacher' => 'Misty Copeland',
    ]);
    Event::factory()->create([
        'course_id' => $this->course->id,
        'start_time' => CarbonImmutable::parse('2026-06-01 18:30:00', 'UTC'),
        'end_time' => CarbonImmutable::parse('2026-06-01 19:30:00', 'UTC'),
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

    $component = livewire(ProductDetails::class, ['product' => $this->product->refresh()])
        ->assertSee('product-gallery.jpg')
        ->assertSee('course-gallery.jpg')
        ->assertSeeInOrder([
            'product-gallery.jpg',
            'course-gallery.jpg',
        ])
        ->assertSeeHtml('<eac-product-gallery')
        ->assertSeeHtml('data-js-as-module="true"')
        ->assertSeeHtml("x-load-js=\"['\\/build\\/assets\\/product-gallery.js']\"")
        ->assertSeeHtml('data-product-gallery-item')
        ->assertSee('Open product-gallery in the image viewer')
        ->assertSee('Open course-gallery in the image viewer');

    expect(mb_substr_count($component->html(), 'data-product-gallery-item'))->toBe(2);
});

it('renders the lightbox hook for a single gallery image', function () {
    $this->product->addMedia(UploadedFile::fake()->image('only-gallery-image.jpg'))
        ->toMediaCollection('images');

    livewire(ProductDetails::class, ['product' => $this->product->refresh()])
        ->assertSeeHtml('<eac-product-gallery')
        ->assertSee('only-gallery-image.jpg')
        ->assertDontSee('No product images are available.');
});

it('keeps the existing empty gallery state without loading the viewer', function () {
    livewire(ProductDetails::class, ['product' => $this->product])
        ->assertSee('No product images are available.')
        ->assertDontSeeHtml('<eac-product-gallery');
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

it('adds products without opening a modal when no extra information is needed', function () {
    livewire(ProductDetails::class, ['product' => $this->product])
        ->mountAction('addToCart')
        ->assertActionNotMounted()
        ->assertNotified('Added to cart');

    expect(CartItem::query()
        ->where('user_id', auth()->id())
        ->where('product_id', $this->product->id)
        ->value('quantity'))->toBe(1);
});

it('can add a custom amount gift card', function () {
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create();

    livewire(ProductDetails::class, ['product' => $giftCardProduct])
        ->assertSee('Name your price from $5.00')
        ->callAction('addToCart', data: [
            'custom_gift_card_amount' => 75,
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Added to cart');

    expect(CartItem::query()
        ->where('user_id', auth()->id())
        ->where('product_id', $giftCardProduct->id)
        ->value('custom_gift_card_amount'))->toBe(7500);
});

it('opens the add to cart modal when extra information is needed', function () {
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create();

    livewire(ProductDetails::class, ['product' => $giftCardProduct])
        ->mountAction('addToCart')
        ->assertActionMounted('addToCart')
        ->assertSchemaComponentExists('custom_gift_card_amount', 'mountedActionSchema0');
});

it('asks purchaser questions when adding from product details and stores the answer', function (): void {
    $question = ProductQuestion::factory()->for($this->product)->required()->create([
        'question' => 'Dancer name',
    ]);

    $component = livewire(ProductDetails::class, ['product' => $this->product->refresh()])
        ->mountAction('addToCart')
        ->assertActionMounted('addToCart');
    $schemaName = $component->instance()->getMountedActionSchemaName();
    $fields = collect($component->instance()->{$schemaName}->getFlatFields(withHidden: true, withAbsoluteKeys: true));

    expect($fields->contains(fn ($field): bool => $field->getName() === "question_{$question->id}"))->toBeTrue();

    $component
        ->fillForm([
            'question_answers' => [
                1 => ["question_{$question->id}" => 'Avery'],
            ],
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Added to cart');

    $cartItem = CartItem::query()
        ->where('user_id', auth()->id())
        ->where('product_id', $this->product->id)
        ->firstOrFail();

    expect($cartItem->storedQuestionAnswers())->toBe([
        1 => ["question_{$question->id}" => 'Avery'],
    ]);
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
    $product = Product::factory()->create(['price' => 5000]);
    $product->requiredCourses()->attach($requiredCourse);

    $this->get(ProductDetails::getUrl(['product' => $product], false))
        ->assertRedirect(Store::getUrl());
});

it('shows all course and competition team eligibility requirements', function () {
    $requiredCourses = Course::factory(2)->create();
    $season = CompetitionSeason::factory()->current()->create(['name' => '2026 Competition Season']);
    $requiredTeams = CompetitionTeam::factory(2)->for($season, 'season')->create();
    $product = Product::factory()->create(['price' => 5000]);
    $product->requiredCourses()->attach($requiredCourses);
    $product->requiredCompetitionTeams()->attach($requiredTeams);

    Enrollment::factory()->create([
        'course_id' => $requiredCourses->last()->id,
        'user_id' => auth()->id(),
    ]);
    Student::factory()
        ->for(auth()->user())
        ->create()
        ->competitionTeams()
        ->attach($requiredTeams->last());

    livewire(ProductDetails::class, ['product' => $product])
        ->assertOk()
        ->assertSee('Requires Enrollment In At Least One Of')
        ->assertSee($requiredCourses->first()->name)
        ->assertSee($requiredCourses->last()->name)
        ->assertSee('Requires Membership In At Least One Of')
        ->assertSee('2026 Competition Season')
        ->assertSee($requiredTeams->first()->name)
        ->assertSee($requiredTeams->last()->name);
});
