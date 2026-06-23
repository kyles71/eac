<?php

declare(strict_types=1);

use App\Enums\CreditGrantStatus;
use App\Enums\CreditTransactionType;
use App\Enums\ProductType;
use App\Filament\Admin\Resources\CreditGrants\Pages\CreateCreditGrant;
use App\Filament\Admin\Resources\CreditGrants\Pages\ListCreditGrants;
use App\Filament\Admin\Resources\CreditGrants\Pages\ViewCreditGrant;
use App\Filament\Admin\Resources\CreditGrants\Widgets\CreditGrantStats;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Models\CreditGrant;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('renders the global store credit ledger', function () {
    CreditGrant::factory()->count(2)->create();

    livewire(ListCreditGrants::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords(CreditGrant::all());
});

it('issues restricted credit from the global create page', function () {
    $recipient = User::factory()->create();
    $product = Product::factory()->create();

    livewire(CreateCreditGrant::class)
        ->fillForm([
            'user_id' => $recipient->id,
            'initial_amount' => '50.00',
            'description' => 'Summer registration assistance',
            'expires_on' => '2026-12-31',
            'restricted_to_product_type' => ProductType::Course->value,
            'product_ids' => [$product->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $grant = CreditGrant::query()->where('user_id', $recipient->id)->firstOrFail();

    expect($grant->initial_amount)->toBe(5000)
        ->and($grant->remaining_amount)->toBe(5000)
        ->and($grant->restricted_to_product_type)->toBe(ProductType::Course)
        ->and($grant->products->modelKeys())->toBe([$product->id])
        ->and($grant->granted_by_user_id)->toBe(auth()->id());
});

it('issues credit from the user view', function () {
    $recipient = User::factory()->create();

    livewire(ViewUser::class, ['record' => $recipient->id])
        ->callAction('issueCreditGrant', data: [
            'initial_amount' => '25.00',
            'description' => 'Account correction',
            'expires_on' => null,
            'restricted_to_product_type' => null,
            'product_ids' => [],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Store credit issued');

    assertDatabaseHas(CreditGrant::class, [
        'user_id' => $recipient->id,
        'initial_amount' => 2500,
        'remaining_amount' => 2500,
        'description' => 'Account correction',
        'granted_by_user_id' => auth()->id(),
    ]);
});

it('revokes only the unused remainder with a reason', function () {
    $grant = CreditGrant::factory()->amount(4000)->create();

    livewire(ViewCreditGrant::class, ['record' => $grant->id])
        ->callAction('revokeCreditGrant', data: [
            'reason' => 'Issued to the wrong account',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Store credit revoked');

    expect($grant->refresh()->status())->toBe(CreditGrantStatus::Revoked)
        ->and($grant->remaining_amount)->toBe(4000)
        ->and($grant->revocation_reason)->toBe('Issued to the wrong account')
        ->and($grant->transactions()->where('type', CreditTransactionType::Revocation)->exists())->toBeTrue();
});

it('shows issued, used, available, expired, and revoked totals', function () {
    $active = CreditGrant::factory()->amount(5000)->create(['remaining_amount' => 3000]);
    $active->transactions()->create([
        'user_id' => $active->user_id,
        'amount' => -2000,
        'type' => CreditTransactionType::CheckoutDebit,
    ]);
    CreditGrant::factory()->amount(2500)->expired()->create();
    CreditGrant::factory()->amount(1000)->create([
        'revoked_at' => now(),
        'revocation_reason' => 'Correction',
    ]);

    livewire(CreditGrantStats::class)
        ->assertSee('$85.00')
        ->assertSee('$20.00')
        ->assertSee('$30.00')
        ->assertSee('$25.00')
        ->assertSee('$10.00');
});
