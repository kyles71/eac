<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Filament\Admin\Resources\DiscountCodes\Pages\CreateDiscountCode;
use App\Filament\Admin\Resources\DiscountCodes\Pages\EditDiscountCode;
use App\Filament\Admin\Resources\DiscountCodes\Pages\ListDiscountCodes;
use App\Models\DiscountCode;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('can render the discount codes index page', function () {
    livewire(ListDiscountCodes::class)
        ->assertSuccessful();
});

it('can render the create discount code page', function () {
    livewire(CreateDiscountCode::class)
        ->assertSuccessful();
});

it('can render the edit discount code page', function () {
    $code = DiscountCode::factory()->create();

    livewire(EditDiscountCode::class, ['record' => $code->getRouteKey()])
        ->assertSuccessful();
});

it('can list discount codes', function () {
    $codes = DiscountCode::factory()->count(3)->create();

    livewire(ListDiscountCodes::class)
        ->loadTable()
        ->assertCanSeeTableRecords($codes);
});

it('can create a percentage discount code', function () {
    livewire(CreateDiscountCode::class)
        ->fillForm([
            'code' => 'SUMMER20',
            'type' => DiscountType::Percentage->value,
            'value' => 20,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(DiscountCode::class, [
        'code' => 'SUMMER20',
        'type' => DiscountType::Percentage->value,
        'value' => 20,
    ]);
});

it('can create a fixed amount discount code', function () {
    livewire(CreateDiscountCode::class)
        ->fillForm([
            'code' => 'SAVE10',
            'type' => DiscountType::FixedAmount->value,
            'value' => 10,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(DiscountCode::class, [
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
