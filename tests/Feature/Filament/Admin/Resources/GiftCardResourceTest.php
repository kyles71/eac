<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\GiftCards\Pages\CreateGiftCard;
use App\Filament\Admin\Resources\GiftCards\Pages\EditGiftCard;
use App\Filament\Admin\Resources\GiftCards\Pages\ListGiftCards;
use App\Models\GiftCard;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('can render the gift cards index page', function () {
    livewire(ListGiftCards::class)
        ->assertSuccessful();
});

it('can render the create gift card page', function () {
    livewire(CreateGiftCard::class)
        ->assertSuccessful();
});

it('can render the edit gift card page', function () {
    $giftCard = GiftCard::factory()->create();

    livewire(EditGiftCard::class, ['record' => $giftCard->getRouteKey()])
        ->assertSuccessful();
});

it('can list gift cards', function () {
    $giftCards = GiftCard::factory()->count(3)->create();

    livewire(ListGiftCards::class)
        ->loadTable()
        ->assertCanSeeTableRecords($giftCards);
});

it('can create a gift card', function () {
    $user = User::factory()->create();

    livewire(CreateGiftCard::class)
        ->fillForm([
            'code' => 'TESTGIFTCARD123',
            'initial_amount' => 50,
            'remaining_amount' => 50,
            'purchased_by_user_id' => $user->id,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(GiftCard::class, [
        'code' => 'TESTGIFTCARD123',
        'purchased_by_user_id' => $user->id,
    ]);
});

it('can search gift cards by code', function () {
    $searchCard = GiftCard::factory()->create(['code' => 'FINDMEGC']);
    $otherCard = GiftCard::factory()->create(['code' => 'OTHERGC']);

    livewire(ListGiftCards::class)
        ->loadTable()
        ->searchTable('FINDMEGC')
        ->assertCanSeeTableRecords(collect([$searchCard]))
        ->assertCanNotSeeTableRecords(collect([$otherCard]));
});

it('has the expected table columns', function () {
    livewire(ListGiftCards::class)
        ->loadTable()
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('initial_amount')
        ->assertTableColumnExists('remaining_amount')
        ->assertTableColumnExists('is_active');
});
