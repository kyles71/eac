<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class RemoveFromCart
{
    public function handle(User $user, int $cartItemId): void
    {
        DB::transaction(function () use ($user, $cartItemId): void {
            $deleted = CartItem::query()
                ->where('id', $cartItemId)
                ->where('user_id', $user->id)
                ->delete();

            if ($deleted === 0) {
                throw new InvalidArgumentException('Cart item not found.');
            }
        });
    }
}
