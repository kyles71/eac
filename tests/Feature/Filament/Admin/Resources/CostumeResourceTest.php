<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Costumes\Pages\ListCostumes;
use App\Models\Costume;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('can render the costumes index page', function () {
    livewire(ListCostumes::class)
        ->assertOk();
});

it('can list costumes', function () {
    $costumes = Costume::factory(3)->create();

    livewire(ListCostumes::class)
        ->loadTable()
        ->assertCanSeeTableRecords($costumes);
});

it('can search costumes by name', function () {
    $costume1 = Costume::factory()->create(['name' => 'Swan Lake Tutu']);
    $costume2 = Costume::factory()->create(['name' => 'Nutcracker Soldier']);

    livewire(ListCostumes::class)
        ->loadTable()
        ->searchTable('Swan Lake')
        ->assertCanSeeTableRecords([$costume1])
        ->assertCanNotSeeTableRecords([$costume2]);
});

it('can create a costume', function () {
    livewire(ListCostumes::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'New Costume',
        ])
        ->assertNotified();

    assertDatabaseHas('costumes', [
        'name' => 'New Costume',
    ]);
});

it('requires name to create a costume', function () {
    livewire(ListCostumes::class)
        ->callAction(CreateAction::class, data: [
            'name' => '',
        ])
        ->assertHasActionErrors(['name' => 'required']);
});

it('has required columns', function (string $column) {
    livewire(ListCostumes::class)
        ->assertTableColumnExists($column);
})->with(['id', 'name']);
