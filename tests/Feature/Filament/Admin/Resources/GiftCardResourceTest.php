<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Filament\Actions\AssignAndRedeemGiftCardAction;
use App\Filament\Admin\Resources\GiftCards\Pages\ListGiftCards;
use App\Models\CreditGrant;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Kyle\FilamentMailManager\Mail\ManagedMail;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('can render the gift cards index page', function () {
    livewire(ListGiftCards::class)
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

    livewire(ListGiftCards::class)
        ->callAction(CreateAction::class, data: [
            'code' => 'TESTGIFTCARD123',
            'initial_amount' => '50.00',
            'purchased_by_user_id' => $user->id,
        ])
        ->assertNotified();

    assertDatabaseHas(GiftCard::class, [
        'code' => 'TESTGIFTCARD123',
        'initial_amount' => 5000,
        'remaining_amount' => 5000,
        'purchased_by_user_id' => $user->id,
        'redeemed_by_user_id' => null,
        'redeemed_at' => null,
        'is_active' => true,
    ]);
});

it('prefills create gift card codes with the shared generator', function () {
    GiftCard::factory()->create(['code' => 'DUPLICATECODE123']);

    Str::createRandomStringsUsingSequence([
        'duplicatecode123',
        'uniquecode000001',
    ]);

    try {
        livewire(ListGiftCards::class)
            ->mountAction(CreateAction::class)
            ->assertActionDataSet(fn (array $data): bool => ($data['code'] ?? null) === 'UNIQUECODE000001');
    } finally {
        Str::createRandomStringsNormally();
    }
});

it('assigns and redeems an unredeemed gift card for a recipient', function () {
    Mail::fake();

    $purchaser = User::factory()->create([
        'first_name' => 'Pat',
        'last_name' => 'Purchaser',
        'email' => 'purchaser@example.com',
    ]);
    $recipient = User::factory()->create([
        'first_name' => 'Riley',
        'last_name' => 'Recipient',
        'email' => 'recipient@example.com',
    ]);
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->denomination(5000)
        ->create();
    $giftCard = GiftCard::factory()
        ->forType($giftCardType)
        ->amount(5000)
        ->create([
            'code' => 'ASSIGN-CARD-50',
            'purchased_by_user_id' => $purchaser->id,
        ]);

    livewire(ListGiftCards::class)
        ->loadTable()
        ->assertActionVisible(TestAction::make(AssignAndRedeemGiftCardAction::class)->table($giftCard))
        ->callAction(TestAction::make(AssignAndRedeemGiftCardAction::class)->table($giftCard), data: [
            'recipient_id' => $recipient->id,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Gift card assigned and redeemed');

    $giftCard->refresh();
    $grant = CreditGrant::query()
        ->where('user_id', $recipient->id)
        ->whereMorphedTo('source', $giftCard)
        ->firstOrFail();

    expect($giftCard->redeemed_by_user_id)->toBe($recipient->id)
        ->and($giftCard->remaining_amount)->toBe(0)
        ->and($giftCard->redeemed_at)->not->toBeNull()
        ->and($grant->initial_amount)->toBe(5000)
        ->and($grant->remaining_amount)->toBe(5000)
        ->and($grant->restricted_to_product_type)->toBe(ProductType::Course);

    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail) use ($recipient): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'gift-card-assigned-redemption'
            && $mail->hasTo($recipient->email)
            && str_contains($rendered->html, 'ASSIGN-CARD-50')
            && str_contains($rendered->html, 'Pat Purchaser')
            && str_contains($rendered->html, 'View Store Credit');
    });
});

it('hides assign and redeem for cards that are no longer redeemable', function () {
    $giftCard = GiftCard::factory()->redeemed()->create();

    livewire(ListGiftCards::class)
        ->loadTable()
        ->assertActionHidden(TestAction::make(AssignAndRedeemGiftCardAction::class)->table($giftCard));
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
        ->assertTableColumnExists('giftCardType.name')
        ->assertTableColumnExists('initial_amount')
        ->assertTableColumnExists('remaining_amount')
        ->assertTableColumnExists('redemption_status')
        ->assertTableColumnExists('purchasedBy.full_name')
        ->assertTableColumnExists('redeemedBy.full_name')
        ->assertTableColumnExists('order.id')
        ->assertTableColumnExists('is_active')
        ->assertTableFilterExists('gift_card_type_id')
        ->assertTableFilterExists('is_active')
        ->assertTableFilterExists('redeemed');
});
