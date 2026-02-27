<?php

declare(strict_types=1);

use App\Filament\User\Pages\Store;
use App\Models\Course;
use App\Models\Product;
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

it('has required columns', function (string $column) {
    livewire(Store::class)
        ->assertTableColumnExists($column);
})->with(['name', 'description', 'price', 'available_spots']);
