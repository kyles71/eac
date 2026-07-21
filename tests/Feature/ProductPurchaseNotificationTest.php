<?php

declare(strict_types=1);

use App\Actions\Store\CreateOrder;
use App\Actions\Store\SendProductPurchaseNotification;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnswer;
use App\Models\User;
use App\Services\Mail\ProductPurchaseNotificationContentService;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\MailManager;

it('registers a customizable product purchase notification email type', function (): void {
    $definition = app(EmailTypeRegistry::class)->get('product-purchase-notification');

    expect($definition->name('en'))->toBe('Product Purchase Notification')
        ->and(array_keys($definition->slotsByMergeTag()))->toBe(['slot.purchase-details']);
});

it('queues one combined staff email containing only opted-in products and their answers', function (): void {
    Mail::fake();
    config()->set('mail.product_purchase_recipient', 'eacdance@outlook.com');

    $user = User::factory()->create([
        'first_name' => 'Kyle',
        'last_name' => 'Smith',
        'email' => 'purchaser@example.com',
    ]);
    $order = Order::factory()->completed()->create(['user_id' => $user->id]);
    $notifiedProduct = Product::factory()->create(['name' => 'Competition <Jacket>']);
    $notifiedItem = OrderItem::factory()->fulfilled()->create([
        'order_id' => $order->id,
        'product_id' => $notifiedProduct->id,
        'purchase_notification_requested' => true,
    ]);
    ProductQuestionAnswer::factory()->create([
        'order_item_id' => $notifiedItem->id,
        'product_question_id' => null,
        'question' => 'Dancer <name>',
        'answer' => 'Avery & Co.',
    ]);
    $ordinaryProduct = Product::factory()->create(['name' => 'Ordinary Product']);
    OrderItem::factory()->fulfilled()->create([
        'order_id' => $order->id,
        'product_id' => $ordinaryProduct->id,
        'purchase_notification_requested' => false,
    ]);

    $notifications = app(SendProductPurchaseNotification::class);

    expect($notifications->handle($order))->toBeTrue()
        ->and($notifications->handle($order))->toBeFalse()
        ->and($order->refresh()->purchase_notification_queued_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, 1);
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'product-purchase-notification'
        && $mail->hasTo('eacdance@outlook.com')
        && $mail->usesMailer('transactional'));

    $payload = app(ProductPurchaseNotificationContentService::class)->for($order);
    $rendered = app(MailManager::class)->render(
        emailTypeKey: 'product-purchase-notification',
        tokens: $payload['tokens'],
        slots: $payload['slots'],
    );

    expect($rendered->html)
        ->toContain('Competition &lt;Jacket&gt;')
        ->toContain('Dancer &lt;name&gt;')
        ->toContain('Avery &amp; Co.')
        ->not->toContain('Ordinary Product');
});

it('does not notify for incomplete orders, orders without opted-in items, or invalid recipients', function (): void {
    Mail::fake();
    $action = app(SendProductPurchaseNotification::class);

    $processingOrder = Order::factory()->create(['status' => OrderStatus::Processing]);
    OrderItem::factory()->create([
        'order_id' => $processingOrder->id,
        'purchase_notification_requested' => true,
    ]);

    $ordinaryOrder = Order::factory()->completed()->create();
    OrderItem::factory()->create([
        'order_id' => $ordinaryOrder->id,
        'purchase_notification_requested' => false,
    ]);

    $invalidRecipientOrder = Order::factory()->completed()->create();
    OrderItem::factory()->create([
        'order_id' => $invalidRecipientOrder->id,
        'purchase_notification_requested' => true,
    ]);
    config()->set('mail.product_purchase_recipient', 'not-an-email');

    expect($action->handle($processingOrder))->toBeFalse()
        ->and($action->handle($ordinaryOrder))->toBeFalse()
        ->and($action->handle($invalidRecipientOrder))->toBeFalse();

    Mail::assertNothingQueued();
});

it('queues the receipt and staff notification after a zero-balance order completes', function (): void {
    Mail::fake();
    config()->set('mail.product_purchase_recipient', 'eacdance@outlook.com');

    $user = User::factory()->create();
    $product = Product::factory()->standalone()->create([
        'price' => 5000,
        'send_purchase_notification' => true,
    ]);
    $question = ProductQuestion::factory()->for($product)->required()->create([
        'question' => 'Dancer name',
    ]);
    CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'question_answers' => [
            1 => ["question_{$question->id}" => 'Avery'],
        ],
    ]);
    $discount = DiscountCode::factory()->fixedAmount(5000)->create();

    $order = app(CreateOrder::class)->handle($user, $discount)->refresh();

    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->receipt_queued_at)->not->toBeNull()
        ->and($order->purchase_notification_queued_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, 2);
});
