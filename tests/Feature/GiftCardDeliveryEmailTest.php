<?php

declare(strict_types=1);

use App\Actions\Store\SendGiftCardDeliveryEmails;
use App\Enums\OrderStatus;
use App\Filament\User\Pages\Billing;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\Order;
use App\Models\User;
use App\Services\Mail\GiftCardAssignedRedemptionContentService;
use App\Services\Mail\GiftCardDeliveryContentService;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\MailManager;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

it('registers and renders the editable gift card delivery email', function (): void {
    [$giftCard, $order] = giftCardDeliveryFixture([
        'code' => 'GC-<unsafe>',
    ], [
        'first_name' => 'Kyle <script>',
    ]);

    $definition = app(EmailTypeRegistry::class)->get('gift-card-delivery');
    $payload = app(GiftCardDeliveryContentService::class)->for($giftCard);
    $rendered = app(MailManager::class)->render(
        emailTypeKey: 'gift-card-delivery',
        tokens: $payload['tokens'],
        slots: $payload['slots'],
    );

    expect($definition->category)->toBe('transactional')
        ->and(array_keys($definition->tokensByKey()))->toContain(
            'purchaser.first_name',
            'gift_card.code',
            'gift_card.value',
            'gift_card.restrictions',
            'order.number',
            'order.date',
        )
        ->and(array_keys($definition->slotsByMergeTag()))->toBe(['slot.redeem-action'])
        ->and($rendered->subject)->toBe('Your $50.00 gift card from '.config('app.name'))
        ->and($rendered->html)
        ->toContain('GC-&lt;unsafe&gt;')
        ->toContain('Unrestricted')
        ->toContain("#{$order->id}")
        ->toContain(Billing::getUrl(['tab' => 'credits'], panel: 'user'))
        ->toContain('Redeem Gift Card')
        ->not->toContain('<script>');
});

it('registers and renders the editable assigned gift card redemption email', function (): void {
    $recipient = User::factory()->create([
        'first_name' => 'Riley',
        'last_name' => 'Recipient',
        'email' => 'recipient@example.com',
    ]);
    $purchaser = User::factory()->create([
        'first_name' => 'Pat <script>',
        'last_name' => 'Purchaser',
        'email' => 'purchaser@example.com',
    ]);
    $giftCardType = GiftCardType::factory()->denomination(5000)->create();
    $giftCard = GiftCard::factory()
        ->forType($giftCardType)
        ->amount(5000)
        ->redeemed($recipient)
        ->create([
            'code' => 'ASSIGNED-<unsafe>',
            'purchased_by_user_id' => $purchaser->id,
        ]);

    $definition = app(EmailTypeRegistry::class)->get('gift-card-assigned-redemption');
    $payload = app(GiftCardAssignedRedemptionContentService::class)->for($giftCard, $recipient);
    $rendered = app(MailManager::class)->render(
        emailTypeKey: 'gift-card-assigned-redemption',
        tokens: $payload['tokens'],
        slots: $payload['slots'],
    );

    expect($definition->category)->toBe('transactional')
        ->and(array_keys($definition->tokensByKey()))->toContain(
            'recipient.first_name',
            'recipient.full_name',
            'purchaser.first_name',
            'purchaser.full_name',
            'purchaser.email',
            'gift_card.code',
            'gift_card.value',
            'gift_card.restrictions',
            'gift_card.redemption_date',
        )
        ->and(array_keys($definition->slotsByMergeTag()))->toBe(['slot.billing-action'])
        ->and($rendered->subject)->toBe('$50.00 in store credit has been added to your '.config('app.name').' account')
        ->and($rendered->html)
        ->toContain('ASSIGNED-&lt;unsafe&gt;')
        ->toContain('Pat &lt;script&gt; Purchaser')
        ->toContain(Billing::getUrl(['tab' => 'credits'], panel: 'user'))
        ->toContain('View Store Credit')
        ->not->toContain('<script>');
});

it('queues one transactional email per gift card and does not queue duplicates', function (): void {
    Mail::fake();
    [$firstGiftCard, $order] = giftCardDeliveryFixture(['code' => 'FIRST-CODE']);
    $secondGiftCard = GiftCard::factory()->forType($firstGiftCard->giftCardType)->create([
        'code' => 'SECOND-CODE',
        'initial_amount' => 5000,
        'remaining_amount' => 5000,
        'purchased_by_user_id' => $order->user_id,
        'order_id' => $order->id,
    ]);

    $action = app(SendGiftCardDeliveryEmails::class);

    expect($action->handle($order))->toBe(2)
        ->and($action->handle($order))->toBe(0)
        ->and($firstGiftCard->refresh()->delivery_email_queued_at)->not->toBeNull()
        ->and($secondGiftCard->refresh()->delivery_email_queued_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, 2);

    foreach (['FIRST-CODE', 'SECOND-CODE'] as $code) {
        Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail) use ($code, $order): bool {
            $rendered = $mail->getRenderedEmail();

            return $mail->emailTypeKey === 'gift-card-delivery'
                && $mail->hasTo($order->user->email)
                && $mail->usesMailer('transactional')
                && str_contains($rendered->html, $code);
        });
    }
});

it('leaves gift cards unmarked when the managed email is disabled', function (): void {
    Mail::fake();
    [$giftCard, $order] = giftCardDeliveryFixture();

    app(ManagedTemplateRepository::class)->saveOverride('gift-card-delivery', [
        'is_active' => false,
    ]);

    expect(app(SendGiftCardDeliveryEmails::class)->handle($order))->toBe(0)
        ->and($giftCard->refresh()->delivery_email_queued_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('does not queue gift card delivery for an incomplete order', function (): void {
    Mail::fake();
    [$giftCard, $order] = giftCardDeliveryFixture(orderStatus: OrderStatus::Processing);

    expect(app(SendGiftCardDeliveryEmails::class)->handle($order))->toBe(0)
        ->and($giftCard->refresh()->delivery_email_queued_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('does not queue gift card delivery for an invalid purchaser email', function (): void {
    Mail::fake();
    [$giftCard, $order] = giftCardDeliveryFixture(userAttributes: [
        'email' => 'invalid-email',
    ]);

    expect(app(SendGiftCardDeliveryEmails::class)->handle($order))->toBe(0)
        ->and($giftCard->refresh()->delivery_email_queued_at)->toBeNull();

    Mail::assertNothingQueued();
});

/**
 * @param  array<string, mixed>  $giftCardAttributes
 * @param  array<string, mixed>  $userAttributes
 * @return array{GiftCard, Order}
 */
function giftCardDeliveryFixture(
    array $giftCardAttributes = [],
    array $userAttributes = [],
    OrderStatus $orderStatus = OrderStatus::Completed,
): array {
    $user = User::factory()->create([
        'first_name' => 'Kyle',
        'last_name' => 'Smith',
        'email' => 'gift-card@example.com',
        ...$userAttributes,
    ]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => $orderStatus,
        'subtotal' => 5000,
        'total' => 5000,
    ]);
    $giftCardType = GiftCardType::factory()->denomination(5000)->create();
    $giftCard = GiftCard::factory()->forType($giftCardType)->create([
        'code' => 'GIFT-CARD-CODE',
        'initial_amount' => 5000,
        'remaining_amount' => 5000,
        'purchased_by_user_id' => $user->id,
        'order_id' => $order->id,
        ...$giftCardAttributes,
    ]);

    return [$giftCard, $order];
}
