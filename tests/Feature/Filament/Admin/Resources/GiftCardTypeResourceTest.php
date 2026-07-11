<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\GiftCardTypes\Pages\ListGiftCardTypes;
use App\Models\GiftCardType;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('can render the gift card types index page', function (): void {
    livewire(ListGiftCardTypes::class)
        ->assertSuccessful();
});

it('uses browser side visibility for custom amount fields', function (): void {
    livewire(ListGiftCardTypes::class)
        ->mountAction(CreateAction::class)
        ->assertSchemaComponentExists(
            'allows_custom_amount',
            'mountedActionSchema0',
            fn (Toggle $field): bool => ! $field->isLive(),
        )
        ->assertSchemaComponentExists(
            'minimum_custom_amount',
            'mountedActionSchema0',
            fn (TextInput $field): bool => mb_trim($field->getVisibleJs() ?? '') === "\$get('allows_custom_amount') === true",
        );
});

it('can create a custom amount gift card type', function (): void {
    livewire(ListGiftCardTypes::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Name Your Price Gift Card',
            'denomination' => '50.00',
            'allows_custom_amount' => true,
            'minimum_custom_amount' => '5.00',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified();

    assertDatabaseHas(GiftCardType::class, [
        'name' => 'Name Your Price Gift Card',
        'denomination' => 5000,
        'allows_custom_amount' => true,
        'minimum_custom_amount' => 500,
    ]);
});

it('shows whether gift card types are fixed or custom', function (): void {
    GiftCardType::factory()->denomination(5000)->create(['name' => 'Fixed Gift Card']);
    GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create(['name' => 'Custom Gift Card']);

    livewire(ListGiftCardTypes::class)
        ->loadTable()
        ->assertSee('Fixed')
        ->assertSee('Custom from $5.00');
});
