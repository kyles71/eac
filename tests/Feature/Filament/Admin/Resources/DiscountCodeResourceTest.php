<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Filament\Admin\Resources\DiscountCodes\Pages\ListDiscountCodes;
use App\Models\DiscountCode;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('can render the discount codes index page', function () {
    livewire(ListDiscountCodes::class)
        ->assertSuccessful();
});

it('can list discount codes', function () {
    $codes = DiscountCode::factory()->count(3)->create();

    livewire(ListDiscountCodes::class)
        ->loadTable()
        ->assertCanSeeTableRecords($codes);
});

it('can create a percentage discount code', function () {
    livewire(ListDiscountCodes::class)
        ->callAction(CreateAction::class, data: [
            'code' => 'SUMMER20',
            'type' => DiscountType::Percentage->value,
            'value' => 20,
            'is_active' => true,
        ])
        ->assertNotified();

    assertDatabaseHas(DiscountCode::class, [
        'code' => 'SUMMER20',
        'type' => DiscountType::Percentage->value,
        'value' => 20,
    ]);
});

it('can create a fixed amount discount code', function () {
    livewire(ListDiscountCodes::class)
        ->callAction(CreateAction::class, data: [
            'code' => 'SAVE10',
            'type' => DiscountType::FixedAmount->value,
            'value' => 10,
            'is_active' => true,
        ])
        ->assertNotified();

    assertDatabaseHas(DiscountCode::class, [
        'code' => 'SAVE10',
        'type' => DiscountType::FixedAmount->value,
    ]);
});

it('can search discount codes by code', function () {
    $searchCode = DiscountCode::factory()->create(['code' => 'FINDME']);
    $otherCode = DiscountCode::factory()->create(['code' => 'OTHER']);

    livewire(ListDiscountCodes::class)
        ->loadTable()
        ->searchTable('FINDME')
        ->assertCanSeeTableRecords([$searchCode])
        ->assertCanNotSeeTableRecords([$otherCode]);
});

it('has required columns', function (string $column) {
    livewire(ListDiscountCodes::class)
        ->assertTableColumnExists($column);
})->with([
    'code',
    'type',
    'value',
    'times_used',
    'is_active',
    'expires_at',
]);
