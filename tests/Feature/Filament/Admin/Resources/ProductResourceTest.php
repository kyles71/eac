<?php

declare(strict_types=1);

use App\Enums\DashboardAudience;
use App\Enums\ProductQuestionType;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Filament\Admin\Resources\Products\Pages\ViewProduct;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\Gear;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;

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
})->with(['name', 'price', 'is_active', 'availability_status', 'productable_type', 'available_from', 'available_until', 'created_at', 'updated_at']);

it('has an include linked item images field on the product form', function () {
    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->assertSchemaComponentExists('include_productable_images')
        ->assertSchemaComponentDoesNotExist('ask_purchaser_questions_when_adding_to_cart')
        ->assertSchemaComponentStateSet('include_productable_images', false);
});

it('shows linked item controls before store details on the product form', function () {
    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->assertSeeInOrder(['Linked Item', 'Store Details']);
});

it('shows purchase eligibility controls for every product type', function (?string $productableType) {
    $component = livewire(ListProducts::class)
        ->mountAction(CreateAction::class);

    if ($productableType !== null) {
        $component->fillForm(['productable_type' => $productableType]);
    }

    $component
        ->assertSchemaComponentVisible('requiredCourses')
        ->assertSchemaComponentVisible('requiredCompetitionTeams')
        ->assertSchemaComponentVisible('assignedUsers');
})->with([
    'standalone' => null,
    'course' => Course::class,
    'gear' => Gear::class,
    'gift card' => GiftCardType::class,
]);

it('can configure multiple required courses and competition teams', function () {
    $courses = Course::factory(2)->create();
    $season = CompetitionSeason::factory()->current()->create(['name' => '2026 Competition Season']);
    $teams = CompetitionTeam::factory(2)->for($season, 'season')->create();
    $specificUsers = User::factory(2)->create();

    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'name' => 'Competition Bundle',
            'description' => null,
            'price' => '75.00',
            'is_active' => true,
            'productable_type' => null,
            'productable_id' => null,
            'requiredCourses' => $courses->modelKeys(),
            'requiredCompetitionTeams' => $teams->modelKeys(),
            'assignedUsers' => $specificUsers->modelKeys(),
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    $product = Product::query()->where('name', 'Competition Bundle')->firstOrFail();

    expect($product->requiredCourses()->pluck('courses.id')->sort()->values()->all())
        ->toBe($courses->modelKeys())
        ->and($product->requiredCompetitionTeams()->pluck('competition_teams.id')->sort()->values()->all())
        ->toBe($teams->modelKeys())
        ->and($product->assignedUsers()->pluck('users.id')->sort()->values()->all())
        ->toBe($specificUsers->modelKeys());

    livewire(ViewProduct::class, ['record' => $product->id])
        ->assertSee($courses->first()->name)
        ->assertSee($courses->last()->name)
        ->assertSee('2026 Competition Season')
        ->assertSee($specificUsers->first()->fullName)
        ->assertSee($specificUsers->last()->fullName);
});

it('offers current and future teams while retaining a selected ended team on edit', function () {
    $now = Carbon::parse('2026-07-01 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    try {
        $endingSeason = CompetitionSeason::factory()->create([
            'name' => 'Ending Season',
            'starts_on' => $now->copy()->subMonth()->toDateString(),
            'ends_on' => $now->copy()->addDay()->toDateString(),
        ]);
        $futureSeason = CompetitionSeason::factory()->create([
            'name' => 'Future Season',
            'starts_on' => $now->copy()->addDays(3)->toDateString(),
            'ends_on' => $now->copy()->addYear()->toDateString(),
        ]);
        $hiddenEndedTeam = CompetitionTeam::factory()->for($endingSeason, 'season')->create(['name' => 'Hidden']);
        $selectedEndedTeam = CompetitionTeam::factory()->for($endingSeason, 'season')->create(['name' => 'Selected']);
        $futureTeam = CompetitionTeam::factory()->for($futureSeason, 'season')->create(['name' => 'Upcoming']);
        $product = Product::factory()->create();
        $product->requiredCompetitionTeams()->attach($selectedEndedTeam);

        Carbon::setTestNow($now->copy()->addDays(2));

        $component = livewire(ViewProduct::class, ['record' => $product->id])
            ->mountAction(EditAction::class)
            ->assertSchemaComponentExists(
                'requiredCompetitionTeams',
                checkComponentUsing: fn (Select $select): bool => $select->getOptions() === [
                    $selectedEndedTeam->id => 'Ending Season: Selected (Ended)',
                    $futureTeam->id => 'Future Season: Upcoming (Upcoming)',
                ],
            );

        $component
            ->fillForm(['name' => 'Updated Product'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        expect($hiddenEndedTeam->id)->not->toBeIn([$selectedEndedTeam->id, $futureTeam->id])
            ->and($product->refresh()->requiredCompetitionTeams()->pluck('competition_teams.id')->all())
            ->toBe([$selectedEndedTeam->id]);
    } finally {
        Carbon::setTestNow();
    }
});

it('does not require or save a fixed product price for custom gift card products', function () {
    $giftCardType = GiftCardType::factory()
        ->customAmount(500)
        ->denomination(5000)
        ->create(['name' => 'Open Amount Gift Card']);

    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'name' => 'Open Amount Gift Card',
            'description' => null,
            'price' => '999.00',
            'is_active' => true,
            'productable_type' => GiftCardType::class,
        ])
        ->fillForm([
            'productable_id' => $giftCardType->id,
        ])
        ->assertSchemaComponentHidden('price')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    assertDatabaseHas(Product::class, [
        'name' => 'Open Amount Gift Card',
        'price' => null,
        'productable_type' => GiftCardType::class,
        'productable_id' => $giftCardType->id,
    ]);
});

it('keeps fixed gift card products on fixed product pricing', function () {
    $giftCardType = GiftCardType::factory()
        ->denomination(2500)
        ->create(['name' => '$25 Gift Card']);

    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'name' => '$25 Gift Card',
            'description' => null,
            'is_active' => true,
            'productable_type' => GiftCardType::class,
        ])
        ->fillForm([
            'productable_id' => $giftCardType->id,
        ])
        ->assertSchemaComponentVisible('price')
        ->assertSchemaComponentStateSet('price', '25.00')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    assertDatabaseHas(Product::class, [
        'name' => '$25 Gift Card',
        'price' => 2500,
        'productable_type' => GiftCardType::class,
        'productable_id' => $giftCardType->id,
    ]);
});

it('still requires a fixed price for standalone products', function () {
    livewire(ListProducts::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Competition Shirt',
            'description' => null,
            'price' => null,
            'is_active' => true,
            'productable_type' => null,
            'productable_id' => null,
        ])
        ->assertHasActionErrors(['price' => 'required']);
});

it('can configure ordered purchaser questions and purchase notifications', function (): void {
    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'name' => 'Competition Shirt',
            'description' => null,
            'price' => '50.00',
            'is_active' => true,
            'send_purchase_notification' => true,
            'productable_type' => null,
            'productable_id' => null,
            'questions' => [
                [
                    'question' => 'Dancer name',
                    'type' => ProductQuestionType::Text->value,
                    'is_required' => true,
                    'max_length' => 40,
                    'options' => null,
                    'allows_other' => false,
                ],
                [
                    'question' => 'Shirt size',
                    'type' => ProductQuestionType::Select->value,
                    'is_required' => true,
                    'max_length' => 255,
                    'options' => [
                        ['option' => 'Small'],
                        ['option' => 'Medium'],
                        ['option' => 'Large'],
                    ],
                    'allows_other' => true,
                ],
            ],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    $product = Product::query()->where('name', 'Competition Shirt')->firstOrFail();
    $questions = $product->questions()->get();

    expect($product->send_purchase_notification)->toBeTrue()
        ->and($questions)->toHaveCount(2)
        ->and($questions->pluck('question')->all())->toBe(['Dancer name', 'Shirt size'])
        ->and($questions->first()->type)->toBe(ProductQuestionType::Text)
        ->and($questions->first()->max_length)->toBe(40)
        ->and($questions->last()->type)->toBe(ProductQuestionType::Select)
        ->and($questions->last()->max_length)->toBeNull()
        ->and($questions->last()->options)->toBe(['Small', 'Medium', 'Large'])
        ->and($questions->last()->allows_other)->toBeTrue();

    livewire(ViewProduct::class, ['record' => $product->id])
        ->assertSee('Questions');
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
    Gear::class,
]);

it('keeps the current linked item available when editing a product', function () {
    $currentCourse = Course::factory()->create(['name' => 'Current Linked Course']);
    $availableCourse = Course::factory()->create(['name' => 'Available Course']);
    $otherLinkedCourse = Course::factory()->create(['name' => 'Other Linked Course']);
    $product = Product::factory()->forCourse($currentCourse)->create();
    Product::factory()->forCourse($otherLinkedCourse)->create();

    livewire(ViewProduct::class, [
        'record' => $product->id,
    ])
        ->mountAction(EditAction::class)
        ->assertSchemaComponentExists(
            'productable_id',
            checkComponentUsing: fn (Select $select): bool => $select->getOptions() === [
                $availableCourse->id => 'Available Course',
                $currentCourse->id => 'Current Linked Course',
            ],
        );
});

it('can create a linked product that includes linked item images', function () {
    $gear = Gear::factory()->create();

    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'name' => 'Gear Product',
            'description' => null,
            'price' => '42.50',
            'is_active' => true,
            'productable_type' => Gear::class,
            'productable_id' => $gear->id,
            'include_productable_images' => true,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    assertDatabaseHas(Product::class, [
        'name' => 'Gear Product',
        'price' => 4250,
        'productable_type' => Gear::class,
        'productable_id' => $gear->id,
        'include_productable_images' => true,
    ]);
});

it('can create a product with scheduled availability and early access controls', function () {
    $earlyAccessUser = User::factory()->create([
        'first_name' => 'Early',
        'last_name' => 'Access',
    ]);
    $secondEarlyAccessUser = User::factory()->create([
        'first_name' => 'Second',
        'last_name' => 'Access',
    ]);
    $windowStart = now()->subHour();
    $windowEnd = now()->addDay();

    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'name' => 'Competition Signup',
            'description' => null,
            'price' => '75.00',
            'is_active' => true,
            'available_from' => now()->addDay()->format('Y-m-d H:i:s'),
            'available_until' => now()->addMonth()->format('Y-m-d H:i:s'),
            'earlyAccessWindows' => [
                [
                    'available_from' => $windowStart->format('Y-m-d H:i:s'),
                    'available_until' => $windowEnd->format('Y-m-d H:i:s'),
                    'audiences' => [DashboardAudience::CompTeam->value, DashboardAudience::Teacher->value],
                    'users' => [$earlyAccessUser->id, $secondEarlyAccessUser->id],
                ],
            ],
            'productable_type' => null,
            'productable_id' => null,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    $product = Product::query()->where('name', 'Competition Signup')->firstOrFail();
    $window = $product->earlyAccessWindows()->firstOrFail();

    expect($product->price)->toBe(7500)
        ->and($product->available_from)->not->toBeNull()
        ->and($product->available_until)->not->toBeNull()
        ->and($window->audiences)->toBe([DashboardAudience::CompTeam->value, DashboardAudience::Teacher->value])
        ->and($window->available_from)->not->toBeNull()
        ->and($window->available_until)->not->toBeNull()
        ->and($window->users()->pluck('users.id')->sort()->values()->all())
        ->toBe([$earlyAccessUser->id, $secondEarlyAccessUser->id]);
});

it('can edit product early access windows', function () {
    $product = Product::factory()
        ->availableFrom(now()->addDay())
        ->create(['price' => 5000]);
    $earlyAccessUser = User::factory()->create();
    $replacementUser = User::factory()->create();
    $window = ProductEarlyAccessWindow::factory()
        ->for($product)
        ->create([
            'audiences' => [DashboardAudience::CompTeam->value],
        ]);
    $window->users()->attach($earlyAccessUser);

    $component = livewire(ViewProduct::class, [
        'record' => $product->id,
    ])
        ->mountAction(EditAction::class);

    $windowStateKey = array_key_first($component->instance()->mountedActions[0]['data']['earlyAccessWindows']);

    expect($windowStateKey)->toBe("record-{$window->id}");

    $component
        ->fillForm([
            'name' => $product->name,
            'description' => $product->description,
            'price' => '50.00',
            'is_active' => true,
            'available_from' => now()->addDay()->format('Y-m-d H:i:s'),
            'available_until' => null,
            'earlyAccessWindows' => [
                $windowStateKey => [
                    'available_from' => now()->subMinutes(30)->format('Y-m-d H:i:s'),
                    'available_until' => null,
                    'audiences' => [DashboardAudience::Teacher->value],
                    'users' => [$replacementUser->id],
                ],
            ],
            'productable_type' => null,
            'productable_id' => null,
            'include_productable_images' => false,
            'requiredCourses' => [],
            'requiredCompetitionTeams' => [],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    $window->refresh();

    expect($window->audiences)->toBe([DashboardAudience::Teacher->value])
        ->and($window->users()->pluck('users.id')->all())->toBe([$replacementUser->id]);
});

it('requires available until to be after available from', function () {
    livewire(ListProducts::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Invalid Window',
            'description' => null,
            'price' => '75.00',
            'is_active' => true,
            'available_from' => now()->addDay()->format('Y-m-d H:i:s'),
            'available_until' => now()->format('Y-m-d H:i:s'),
            'productable_type' => null,
            'productable_id' => null,
        ])
        ->assertHasActionErrors(['available_until' => 'after']);
});

it('requires an early access window to target at least one audience or user', function () {
    livewire(ListProducts::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Untargeted Window',
            'description' => null,
            'price' => '75.00',
            'is_active' => true,
            'available_from' => now()->addDay()->format('Y-m-d H:i:s'),
            'available_until' => null,
            'earlyAccessWindows' => [
                [
                    'available_from' => now()->format('Y-m-d H:i:s'),
                    'available_until' => null,
                    'audiences' => [],
                    'users' => [],
                ],
            ],
            'productable_type' => null,
            'productable_id' => null,
        ])
        ->assertHasActionErrors([
            'earlyAccessWindows.0.audiences' => 'required',
            'earlyAccessWindows.0.users' => 'required',
        ]);
});

it('requires an early access window end to be after its start', function () {
    livewire(ListProducts::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Invalid Early Window',
            'description' => null,
            'price' => '75.00',
            'is_active' => true,
            'available_from' => now()->addDay()->format('Y-m-d H:i:s'),
            'available_until' => null,
            'earlyAccessWindows' => [
                [
                    'available_from' => now()->addHour()->format('Y-m-d H:i:s'),
                    'available_until' => now()->format('Y-m-d H:i:s'),
                    'audiences' => [DashboardAudience::CompTeam->value],
                    'users' => [],
                ],
            ],
            'productable_type' => null,
            'productable_id' => null,
        ])
        ->assertHasActionErrors(['earlyAccessWindows.0.available_until' => 'after']);
});

it('formats product type labels', function () {
    $courseProduct = Product::factory()->forCourse()->create();
    $giftCardProduct = Product::factory()->forGiftCardType()->create();
    $gearProduct = Product::factory()->forGear()->create();
    $standaloneProduct = Product::factory()->standalone()->create();

    livewire(ListProducts::class)
        ->loadTable()
        ->assertTableColumnFormattedStateSet('productable_type', 'Course', $courseProduct)
        ->assertTableColumnFormattedStateSet('productable_type', 'Gift Card', $giftCardProduct)
        ->assertTableColumnFormattedStateSet('productable_type', 'Gear', $gearProduct)
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
