<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Gear\Pages\ListGear;
use App\Models\Gear;
use Filament\Actions\CreateAction;
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
})->with(['name']);
