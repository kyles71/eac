<?php

declare(strict_types=1);

use App\Actions\Mail\SendAbandonedCartReminders;
use App\Actions\Store\UpdateCartQuantity;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

it('registers the customizable abandoned cart reminder with user and cart data', function (): void {
    $definition = app(EmailTypeRegistry::class)->get('abandoned-cart-reminder');

    expect($definition->category)->toBe('transactional')
        ->and(array_keys($definition->tokensByKey()))
        ->toContain('user.first_name', 'cart_items.count', 'cart_items.total')
        ->and(array_keys($definition->slotsByMergeTag()))->toBe(['slot.cart-items']);
});

it('reminds once for available cart items older than 24 hours and excludes sold-out items', function (): void {
    Mail::fake();
    $user = User::factory()->create([
        'first_name' => 'Jamie',
        'email' => 'cart@example.com',
    ]);
    $availableProduct = Product::factory()->standalone()->create([
        'name' => 'Dance <Bag>',
        'price' => 2500,
    ]);
    $course = Course::factory()->create(['capacity' => 1]);
    $soldOutProduct = Product::factory()->forCourse($course)->create([
        'name' => 'Sold Out Class',
        'price' => 5000,
    ]);
    Enrollment::factory()->create(['course_id' => $course->id]);
    $availableItem = CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $availableProduct->id,
        'quantity' => 2,
        'created_at' => now()->subHours(30),
        'updated_at' => now()->subHours(30),
    ]);
    $soldOutItem = CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $soldOutProduct->id,
        'created_at' => now()->subHours(30),
        'updated_at' => now()->subHours(30),
    ]);

    expect(app(SendAbandonedCartReminders::class)->handle())->toBe([
        'users_reminded' => 1,
        'cart_items_marked' => 1,
    ])->and(app(SendAbandonedCartReminders::class)->handle())->toBe([
        'users_reminded' => 0,
        'cart_items_marked' => 0,
    ]);

    expect($availableItem->refresh()->reminder_sent_at)->not->toBeNull()
        ->and($soldOutItem->refresh()->reminder_sent_at)->toBeNull();

    Mail::assertQueued(ManagedMail::class, 1);
    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'abandoned-cart-reminder'
            && $mail->hasTo('cart@example.com')
            && $mail->usesMailer('transactional')
            && $rendered->subject === 'You left 1 item(s) in your cart'
            && str_contains($rendered->html, 'Dance &lt;Bag&gt;')
            && str_contains($rendered->html, '$50.00')
            && ! str_contains($rendered->html, 'Sold Out Class');
    });
});

it('does not send when every eligible cart item is sold out', function (): void {
    Mail::fake();
    $course = Course::factory()->create(['capacity' => 1]);
    $product = Product::factory()->forCourse($course)->create();
    Enrollment::factory()->create(['course_id' => $course->id]);
    $cartItem = CartItem::factory()->create([
        'product_id' => $product->id,
        'created_at' => now()->subHours(30),
        'updated_at' => now()->subHours(30),
    ]);

    expect(app(SendAbandonedCartReminders::class)->handle())->toBe([
        'users_reminded' => 0,
        'cart_items_marked' => 0,
    ])->and($cartItem->refresh()->reminder_sent_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('resets reminder eligibility when a cart quantity changes', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->standalone()->create();
    $cartItem = CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'reminder_sent_at' => now()->subDay(),
    ]);

    app(UpdateCartQuantity::class)->handle($user, $cartItem->id, 2);

    expect($cartItem->refresh()->quantity)->toBe(2)
        ->and($cartItem->reminder_sent_at)->toBeNull();
});

it('does not mark cart items when the reminder type is disabled', function (): void {
    Mail::fake();
    $cartItem = CartItem::factory()->create([
        'created_at' => now()->subHours(30),
        'updated_at' => now()->subHours(30),
    ]);
    app(ManagedTemplateRepository::class)->saveOverride('abandoned-cart-reminder', [
        'is_active' => false,
    ]);

    expect(app(SendAbandonedCartReminders::class)->handle())->toBe([
        'users_reminded' => 0,
        'cart_items_marked' => 0,
    ])->and($cartItem->refresh()->reminder_sent_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('runs abandoned cart reminders through the command', function (): void {
    Mail::fake();
    CartItem::factory()->create([
        'created_at' => now()->subHours(30),
        'updated_at' => now()->subHours(30),
    ]);

    $this->artisan('cart:send-abandoned-reminders')
        ->expectsOutput('Reminded 1 user(s) about 1 cart item(s).')
        ->assertSuccessful();

    Mail::assertQueued(ManagedMail::class, 1);
});
