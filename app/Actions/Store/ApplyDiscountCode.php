<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Models\DiscountCode;
use App\Models\User;
use InvalidArgumentException;

final readonly class ApplyDiscountCode
{
    /**
     * Validate and return the discount code if applicable.
     *
     * @param  array<int, int>  $productIds
     */
    public function handle(string $code, User $user, int $subtotal, array $productIds = []): DiscountCode
    {
        $discountCode = DiscountCode::query()
            ->where('code', $code)
            ->first();

        if ($discountCode === null) {
            throw new InvalidArgumentException('Invalid discount code.');
        }

        if (! $discountCode->isValidForUser($user)) {
            throw new InvalidArgumentException('This discount code is no longer valid.');
        }

        if ($discountCode->min_order_amount !== null && $subtotal < $discountCode->min_order_amount) {
            $minFormatted = '$'.number_format($discountCode->min_order_amount / 100, 2);

            throw new InvalidArgumentException(
                "This discount code requires a minimum order of {$minFormatted}.",
            );
        }

        // If the code is scoped to specific products, ensure at least one cart product qualifies
        if ($discountCode->products()->count() > 0 && count($productIds) > 0) {
            $matchingProducts = $discountCode->products()
                ->whereIn('products.id', $productIds)
                ->count();

            if ($matchingProducts === 0) {
                throw new InvalidArgumentException(
                    'This discount code does not apply to any items in your cart.',
                );
            }
        }

        return $discountCode;
    }
}
