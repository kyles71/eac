<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\CartItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final readonly class AbandonedCartReminderContent
{
    /**
     * @param  Collection<int, CartItem>  $cartItems
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(User $user, Collection $cartItems): array
    {
        $total = $cartItems->sum(fn (CartItem $cartItem): int => $cartItem->product->price * $cartItem->quantity);

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'user.first_name' => $user->first_name,
                'user.full_name' => mb_trim("{$user->first_name} {$user->last_name}"),
                'user.email' => $user->email,
                'cart_items.count' => (string) $cartItems->count(),
                'cart_items.total' => format_money($total),
            ],
            'slots' => [
                'cart-items' => view('mail.abandoned-cart-items', [
                    'cartItems' => $cartItems,
                    'total' => $total,
                ])->render(),
            ],
        ];
    }
}
