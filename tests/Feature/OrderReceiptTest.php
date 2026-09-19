<?php

declare(strict_types=1);

use App\Actions\Store\SendOrderReceipt;
use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanFrequency;
use App\Models\Costume;
use App\Models\Gear;
use App\Models\Installment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlan;
use App\Models\Product;
use App\Models\ProductQuestionAnswer;
use App\Models\User;
use App\Services\Mail\OrderReceiptContentService;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Enums\LayoutMode;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\MailManager;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

it('renders escaped purchase details and only applicable product content', function (): void {
    $order = receiptOrder();
    $course = Product::factory()->forCourse()->create(['name' => 'Ballet <script>alert(1)</script>']);
    OrderItem::factory()->fulfilled()->create([
        'order_id' => $order->id,
        'product_id' => $course->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    app(ManagedTemplateRepository::class)->saveOverride('order-receipt', [
        'layout_mode' => LayoutMode::None,
        'conditional_sections' => [
            'course' => '<p>COURSE CONTENT for {{ user.first_name }}</p>',
            'gear' => '<p>GEAR CONTENT</p>',
            'gift-card' => '<p>GIFT CARD CONTENT</p>',
            'standalone' => '<p>OTHER CONTENT</p>',
        ],
    ]);

    $payload = app(OrderReceiptContentService::class)->for($order);
    $rendered = app(MailManager::class)->render(
        emailTypeKey: 'order-receipt',
        tokens: $payload['tokens'],
        slots: $payload['slots'],
        conditions: $payload['conditions'],
    );

    expect($rendered->subject)->toBe("Receipt for order #{$order->id}")
        ->and($rendered->html)
        ->toContain('Ballet &lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('COURSE CONTENT')
        ->not->toContain('<script>')
        ->not->toContain('GEAR CONTENT')
        ->not->toContain('GIFT CARD CONTENT')
        ->not->toContain('OTHER CONTENT');
});

it('renders gear content independently from costume content', function (): void {
    $order = receiptOrder();
    $gearProduct = Product::factory()->forGear(Gear::factory()->create())->create();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $gearProduct->id,
    ]);

    app(ManagedTemplateRepository::class)->saveOverride('order-receipt', [
        'layout_mode' => LayoutMode::None,
        'conditional_sections' => [
            'gear' => '<p>GEAR CONTENT</p>',
        ],
    ]);

    $payload = app(OrderReceiptContentService::class)->for($order);
    $rendered = app(MailManager::class)->render(
        emailTypeKey: 'order-receipt',
        tokens: $payload['tokens'],
        slots: $payload['slots'],
        conditions: $payload['conditions'],
    );

    expect($payload['conditions']['gear'])->toBeTrue()
        ->and($payload['conditions']['costume'])->toBeFalse()
        ->and($rendered->html)->toContain('GEAR CONTENT');
});

it('renders costume content for costume purchases', function (): void {
    $order = receiptOrder();
    $costumeProduct = Product::factory()->forCostume(Costume::factory()->create())->create();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $costumeProduct->id,
    ]);

    app(ManagedTemplateRepository::class)->saveOverride('order-receipt', [
        'layout_mode' => LayoutMode::None,
        'conditional_sections' => [
            'costume' => '<p>COSTUME CONTENT</p>',
        ],
    ]);

    $payload = app(OrderReceiptContentService::class)->for($order);
    $rendered = app(MailManager::class)->render(
        emailTypeKey: 'order-receipt',
        tokens: $payload['tokens'],
        slots: $payload['slots'],
        conditions: $payload['conditions'],
    );

    expect($payload['conditions']['costume'])->toBeTrue()
        ->and($payload['conditions']['gear'])->toBeFalse()
        ->and($rendered->html)->toContain('COSTUME CONTENT');
});

it('queues an initial receipt once through the transactional mailer', function (): void {
    Mail::fake();
    $order = receiptOrder();
    OrderItem::factory()->fulfilled()->create(['order_id' => $order->id]);

    $receipts = app(SendOrderReceipt::class);

    expect($receipts->handle($order))->toBeTrue()
        ->and($receipts->handle($order))->toBeFalse()
        ->and($order->refresh()->receipt_queued_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, 1);
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'order-receipt'
        && $mail->hasTo($order->user->email)
        && $mail->usesMailer('transactional'));
});

it('allows a completed receipt to be resent', function (): void {
    Mail::fake();
    $order = receiptOrder(['receipt_queued_at' => now()->subDay()]);
    OrderItem::factory()->fulfilled()->create(['order_id' => $order->id]);

    expect(app(SendOrderReceipt::class)->handle($order, resend: true))->toBeTrue();

    Mail::assertQueued(ManagedMail::class, 1);
});

it('does not send a receipt for an incomplete order', function (): void {
    Mail::fake();
    $order = receiptOrder(['status' => OrderStatus::Processing]);

    expect(app(SendOrderReceipt::class)->handle($order, resend: true))->toBeFalse();
    Mail::assertNothingQueued();
});

it('includes payment plan totals and installment details', function (): void {
    $order = receiptOrder(['total' => 12000, 'payment_plan_fee' => 500]);
    OrderItem::factory()->fulfilled()->create([
        'order_id' => $order->id,
        'unit_price' => 11500,
        'total_price' => 11500,
    ]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'total_amount' => 12000,
        'number_of_installments' => 2,
        'frequency' => PaymentPlanFrequency::Monthly,
    ]);
    Installment::factory()->paid()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 1,
        'amount' => 6000,
        'due_date' => now(),
    ]);
    Installment::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'amount' => 6000,
        'status' => InstallmentStatus::Pending,
        'due_date' => now()->addMonth(),
    ]);

    $payload = app(OrderReceiptContentService::class)->for($order);

    expect($payload['slots']['order-details'])
        ->toContain('Payment Plan')
        ->toContain('Payment Plan Fee')
        ->toContain('Paid: $60.00')
        ->toContain('Remaining: $60.00')
        ->toContain('#2');
});

it('includes escaped per-unit purchaser answers in the emailed receipt', function (): void {
    $order = receiptOrder();
    $product = Product::factory()->create(['name' => 'Competition Shirt']);
    $orderItem = OrderItem::factory()->fulfilled()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);
    ProductQuestionAnswer::factory()->create([
        'order_item_id' => $orderItem->id,
        'product_question_id' => null,
        'unit_number' => 2,
        'question' => 'Dancer <name>',
        'answer' => 'Avery & Co.',
    ]);

    $payload = app(OrderReceiptContentService::class)->for($order);

    expect($payload['slots']['order-details'])
        ->toContain('Item 2 of 2')
        ->toContain('Dancer &lt;name&gt;')
        ->toContain('Avery &amp; Co.')
        ->not->toContain('Dancer <name>');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function receiptOrder(array $attributes = []): Order
{
    $user = User::factory()->create([
        'first_name' => 'Kyle',
        'email' => 'receipt@example.com',
    ]);

    return Order::factory()->completed()->create([
        'user_id' => $user->id,
        'subtotal' => 5000,
        'total' => 5000,
        ...$attributes,
    ]);
}
