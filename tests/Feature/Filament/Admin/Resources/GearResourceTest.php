<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Gear\Pages\ListGear;
use App\Filament\Admin\Resources\Gear\Pages\ViewGear;
use App\Filament\Admin\Resources\Gear\RelationManagers\ProductsRelationManager;
use App\Models\Gear;
use App\Models\Product;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('can render the gear index page', function () {
    livewire(ListGear::class)
        ->assertOk();
});

it('can list gear', function () {
    $gear = Gear::factory(3)->create();

    livewire(ListGear::class)
        ->loadTable()
        ->assertCanSeeTableRecords($gear);
});

it('can view Gear with all of its Product listings', function () {
    $gear = Gear::factory()->create(['name' => 'Competition Jacket']);
    $products = Product::factory(2)->forGear($gear)->create();

    livewire(ViewGear::class, ['record' => $gear->id])
        ->assertOk()
        ->assertSee('Competition Jacket');

    livewire(ProductsRelationManager::class, [
        'ownerRecord' => $gear,
        'pageClass' => ViewGear::class,
    ])
        ->loadTable()
        ->assertCanSeeTableRecords($products);
});

it('creates another Product listing from the Gear detail page', function () {
    $gear = Gear::factory()->create();

    livewire(ProductsRelationManager::class, [
        'ownerRecord' => $gear,
        'pageClass' => ViewGear::class,
    ])
        ->mountAction(TestAction::make(CreateAction::class)->table())
        ->assertSchemaComponentDoesNotExist('productable_type')
        ->assertSchemaComponentDoesNotExist('productable_id')
        ->setActionData([
            'name' => 'Fall 2026 Jacket',
            'description' => null,
            'price' => '75.00',
            'is_active' => true,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    assertDatabaseHas(Product::class, [
        'name' => 'Fall 2026 Jacket',
        'price' => 7500,
        'productable_type' => Gear::class,
        'productable_id' => $gear->id,
    ]);
});

it('offers purchase reports from the Gear list and detail pages', function () {
    $gear = Gear::factory()->create();

    livewire(ListGear::class)
        ->assertActionExists('downloadPurchaseReport');

    livewire(ViewGear::class, ['record' => $gear->id])
        ->assertActionExists('downloadPurchaseReport');
});

it('can search gear by name', function () {
    $gear1 = Gear::factory()->create(['name' => 'Competition Jacket']);
    $gear2 = Gear::factory()->create(['name' => 'Team Backpack']);

    livewire(ListGear::class)
        ->loadTable()
        ->searchTable('Competition')
        ->assertCanSeeTableRecords([$gear1])
        ->assertCanNotSeeTableRecords([$gear2]);
});

it('can create gear', function () {
    livewire(ListGear::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'New Team Jacket',
        ])
        ->assertNotified();

    assertDatabaseHas('gear', [
        'name' => 'New Team Jacket',
    ]);
});

it('requires name to create gear', function () {
    livewire(ListGear::class)
        ->callAction(CreateAction::class, data: [
            'name' => '',
        ])
        ->assertHasActionErrors(['name' => 'required']);
});

it('has required columns', function (string $column) {
    livewire(ListGear::class)
        ->assertTableColumnExists($column);
})->with(['name', 'products_count']);
