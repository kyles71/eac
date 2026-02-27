<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Costumes\Pages\CreateCostume;
use App\Filament\Admin\Resources\Costumes\Pages\EditCostume;
use App\Filament\Admin\Resources\Costumes\Pages\ListCostumes;
use App\Models\Costume;
use Filament\Facades\Filament;

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
    livewire(CreateCostume::class)
        ->fillForm([
            'name' => 'New Costume',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('costumes', [
        'name' => 'New Costume',
    ]);
});

it('can edit a costume', function () {
    $costume = Costume::factory()->create(['name' => 'Old Name']);

    livewire(EditCostume::class, [
        'record' => $costume->id,
    ])
        ->fillForm([
            'name' => 'Updated Name',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($costume->refresh()->name)->toBe('Updated Name');
});

it('requires name to create a costume', function () {
    livewire(CreateCostume::class)
        ->fillForm([
            'name' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

it('has required columns', function (string $column) {
    livewire(ListCostumes::class)
        ->assertTableColumnExists($column);
})->with(['id', 'name']);
