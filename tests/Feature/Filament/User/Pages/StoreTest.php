<?php

declare(strict_types=1);

use App\Enums\ManagedBannerRenderLocation;
use App\Enums\StoreView;
use App\Filament\User\Pages\ProductDetails;
use App\Filament\User\Pages\Store;
use App\Models\CartItem;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\CourseHold;
use App\Models\CourseHoldSeat;
use App\Models\Enrollment;
use App\Models\GiftCardType;
use App\Models\ManagedBanner;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use App\Models\ProductQuestion;
use App\Models\Student;
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

it('defaults new users to card view', function () {
    $component = livewire(Store::class);

    expect($component->instance()->storeView)->toBe(StoreView::Cards)
        ->and($component->instance()->getTable()->getContentGrid())->toBe([
            'default' => 1,
            'md' => 2,
            'xl' => 3,
        ])
        ->and(auth()->user()->refresh()->store_view)->toBe(StoreView::Cards);

    $component
        ->assertActionEnabled(TestAction::make('listView')->table())
        ->assertActionDisabled(TestAction::make('cardView')->table());
});

it('switches to card view and persists the preference', function () {
    auth()->user()->update(['store_view' => StoreView::List]);

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

it('keeps list view for an existing user who selected it', function () {
    auth()->user()->update(['store_view' => StoreView::List]);

    $component = livewire(Store::class);

    expect($component->instance()->storeView)->toBe(StoreView::List)
        ->and($component->instance()->getTable()->getContentGrid())->toBeNull();

    $component
        ->assertActionDisabled(TestAction::make('listView')->table())
        ->assertActionEnabled(TestAction::make('cardView')->table());
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
    $restrictedProduct = Product::factory()->create(['price' => 5000]);
    $restrictedProduct->requiredCourses()->attach($requiredCourse);

    livewire(Store::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->product])
        ->assertCanNotSeeTableRecords([$restrictedProduct]);
});

it('displays products that require an already purchased enrollment', function () {
    $requiredCourse = Course::factory()->create();
    $restrictedProduct = Product::factory()->create(['price' => 5000]);
    $restrictedProduct->requiredCourses()->attach($requiredCourse);

    Enrollment::factory()->create([
        'course_id' => $requiredCourse->id,
        'user_id' => auth()->id(),
    ]);

    livewire(Store::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->product, $restrictedProduct]);
});

it('only displays a team restricted product to members of a required team', function () {
    $season = CompetitionSeason::factory()->current()->create();
    $requiredTeam = CompetitionTeam::factory()->for($season, 'season')->create();
    $restrictedProduct = Product::factory()->create(['price' => 5000]);
    $restrictedProduct->requiredCompetitionTeams()->attach($requiredTeam);

    livewire(Store::class)
        ->loadTable()
        ->assertCanNotSeeTableRecords([$restrictedProduct]);

    Student::factory()
        ->for(auth()->user())
        ->create()
        ->competitionTeams()
        ->attach($requiredTeam);

    livewire(Store::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$restrictedProduct]);
});

it('displays a Product when direct assignment overrides group restrictions', function () {
    $unmatchedCourse = Course::factory()->create();
    $restrictedProduct = Product::factory()->create(['price' => 5000]);
    $restrictedProduct->requiredCourses()->attach($unmatchedCourse);
    $restrictedProduct->assignedUsers()->attach(auth()->user());

    livewire(Store::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$restrictedProduct]);
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

    auth()->user()->update(['store_view' => StoreView::List]);
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

it('adds products from the table without opening a modal when no extra information is needed', function () {
    livewire(Store::class)
        ->mountAction(TestAction::make('addToCart')->table($this->product))
        ->assertActionNotMounted()
        ->assertNotified('Added to cart');

    expect(CartItem::query()
        ->where('user_id', auth()->id())
        ->where('product_id', $this->product->id)
        ->value('quantity'))->toBe(1);
});

it('can add a custom amount gift card from the table', function () {
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create();

    livewire(Store::class)
        ->loadTable()
        ->assertSee('Name your price from $5.00')
        ->callAction(TestAction::make('addToCart')->table($giftCardProduct), data: [
            'custom_gift_card_amount' => 75,
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Added to cart');

    expect(CartItem::query()
        ->where('user_id', auth()->id())
        ->where('product_id', $giftCardProduct->id)
        ->value('custom_gift_card_amount'))->toBe(7500);
});

it('opens the add to cart modal from the table when extra information is needed', function () {
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create();

    livewire(Store::class)
        ->mountAction(TestAction::make('addToCart')->table($giftCardProduct))
        ->assertActionMounted(TestAction::make('addToCart')->table($giftCardProduct))
        ->assertSchemaComponentExists('custom_gift_card_amount', 'mountedActionSchema0');
});

it('asks purchaser questions in the table add to cart modal and stores the answer', function (): void {
    $question = ProductQuestion::factory()->for($this->product)->required()->create([
        'question' => 'Dancer name',
    ]);

    $component = livewire(Store::class)
        ->mountAction(TestAction::make('addToCart')->table($this->product->refresh()))
        ->assertActionMounted(TestAction::make('addToCart')->table($this->product));
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

it('stores digit-only select answers from the table add to cart modal', function (): void {
    $question = ProductQuestion::factory()
        ->for($this->product)
        ->required()
        ->select(['4', '6', 'YXS'])
        ->create([
            'question' => 'Jacket size',
        ]);

    livewire(Store::class)
        ->mountAction(TestAction::make('addToCart')->table($this->product->refresh()))
        ->fillForm([
            'question_answers' => [
                1 => ["question_{$question->id}" => '6'],
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
        1 => ["question_{$question->id}" => '6'],
    ]);
});

it('shows custom gift card amount and purchaser questions in the same table modal', function (): void {
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create();
    $question = ProductQuestion::factory()->for($giftCardProduct)->required()->create([
        'question' => 'Recipient name',
    ]);

    $component = livewire(Store::class)
        ->mountAction(TestAction::make('addToCart')->table($giftCardProduct))
        ->assertSchemaComponentExists('custom_gift_card_amount', 'mountedActionSchema0');
    $schemaName = $component->instance()->getMountedActionSchemaName();
    $fields = collect($component->instance()->{$schemaName}->getFlatFields(withHidden: true, withAbsoluteKeys: true));

    expect($fields->contains(fn ($field): bool => $field->getName() === "question_{$question->id}"))->toBeTrue();
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

it('lets a family add its held seat when public capacity is sold out', function (): void {
    $hold = CourseHold::factory()->create([
        'user_id' => auth()->id(),
        'expires_at' => now()->addDays(2),
    ]);
    CourseHoldSeat::factory()->create([
        'course_hold_id' => $hold->id,
        'course_id' => $this->course->id,
        'locked_unit_price' => 4_000,
    ]);
    Enrollment::factory(4)->create(['course_id' => $this->course->id]);

    livewire(Store::class)
        ->loadTable()
        ->assertActionEnabled(TestAction::make('addToCart')->table($this->product))
        ->callAction(TestAction::make('addToCart')->table($this->product))
        ->assertNotified('Added to cart');

    $cartItem = CartItem::query()->where('user_id', auth()->id())->sole();

    expect($cartItem->course_hold_id)->toBe($hold->id)
        ->and($cartItem->held_unit_price)->toBe(4_000);
});
