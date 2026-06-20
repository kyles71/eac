<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Contracts\HasCapacity;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Mail\AbandonedCartReminderContentService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final readonly class SendAbandonedCartReminders
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private AbandonedCartReminderContentService $content,
    ) {}

    /** @return array{users_reminded: int, cart_items_marked: int} */
    public function handle(?CarbonInterface $dateTime = null): array
    {
        $dateTime = CarbonImmutable::instance($dateTime ?? now());
        $cutoff = $dateTime->subDay();
        $usersReminded = 0;
        $cartItemsMarked = 0;

        User::query()
            ->whereHas('cartItems', fn (Builder $query): Builder => $query
                ->whereNull('reminder_sent_at')
                ->where('updated_at', '<=', $cutoff))
            ->lazyById()
            ->each(function (User $user) use ($dateTime, $cutoff, &$usersReminded, &$cartItemsMarked): void {
                if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                    return;
                }

                $cartItems = CartItem::query()
                    ->where('user_id', $user->id)
                    ->whereNull('reminder_sent_at')
                    ->where('updated_at', '<=', $cutoff)
                    ->with('product.productable')
                    ->get()
                    ->filter(fn (CartItem $cartItem): bool => $this->isAvailable($cartItem, $user, $dateTime))
                    ->values();

                if ($cartItems->isEmpty()) {
                    return;
                }

                $payload = $this->content->for($user, $cartItems);

                if (! $this->managedEmail->handle(
                    recipients: $user->email,
                    emailTypeKey: 'abandoned-cart-reminder',
                    tokens: $payload['tokens'],
                    slots: $payload['slots'],
                )) {
                    return;
                }

                $marked = CartItem::query()
                    ->whereKey($cartItems->modelKeys())
                    ->whereNull('reminder_sent_at')
                    ->update(['reminder_sent_at' => now()]);

                $usersReminded++;
                $cartItemsMarked += $marked;
            });

        return [
            'users_reminded' => $usersReminded,
            'cart_items_marked' => $cartItemsMarked,
        ];
    }

    private function isAvailable(CartItem $cartItem, User $user, CarbonInterface $dateTime): bool
    {
        $product = $cartItem->product;

        if (! $product instanceof Product || ! $product->canBePurchasedBy($user, $dateTime)) {
            return false;
        }

        return ! ($product->productable instanceof HasCapacity)
            || $product->productable->getAvailableCapacity() > 0;
    }
}
