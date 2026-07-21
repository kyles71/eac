<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\HasCapacity;
use App\Models\CartItem;
use App\Models\User;
use App\Services\ProductAvailabilityService;
use App\Services\ProductQuestionAnswerService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class UpdateCartQuantity
{
    /** @param array<int|string, mixed> $questionAnswers */
    public function handle(User $user, int $cartItemId, int $quantity, array $questionAnswers = []): CartItem
    {
        return DB::transaction(function () use ($user, $cartItemId, $quantity, $questionAnswers): CartItem {
            $cartItem = CartItem::query()
                ->where('id', $cartItemId)
                ->where('user_id', $user->id)
                ->with(['product.productable', 'product.questions'])
                ->first();

            if ($cartItem === null) {
                throw new InvalidArgumentException('Cart item not found.');
            }

            if ($quantity < 1) {
                throw new InvalidArgumentException('Quantity must be at least 1.');
            }

            /** @var \App\Models\Product $product */
            $product = $cartItem->product;

            $availability = app(ProductAvailabilityService::class)->resultFor($product, $user);

            if (! $availability->isPurchasable()) {
                throw new InvalidArgumentException($availability->message());
            }

            if ($product->productable instanceof HasCapacity) {
                $availableCapacity = $product->productable->getAvailableCapacity();

                if ($quantity > $availableCapacity) {
                    throw new InvalidArgumentException(
                        "Only {$availableCapacity} spot(s) remaining for this course."
                    );
                }
            }

            $storedQuestionAnswers = $cartItem->storedQuestionAnswers();

            if ($quantity > $cartItem->quantity && $product->hasPurchaserQuestions()) {
                $addedQuantity = $quantity - $cartItem->quantity;
                $newQuestionAnswers = app(ProductQuestionAnswerService::class)->normalizeUnits(
                    $product,
                    $questionAnswers,
                    $addedQuantity,
                    firstUnitNumber: $cartItem->quantity + 1,
                    totalQuantity: $quantity,
                );
                $storedQuestionAnswers = array_replace($storedQuestionAnswers, $newQuestionAnswers);
            }

            if ($quantity < $cartItem->quantity) {
                $storedQuestionAnswers = array_filter(
                    $storedQuestionAnswers,
                    fn (int|string $unitNumber): bool => (int) $unitNumber <= $quantity,
                    ARRAY_FILTER_USE_KEY,
                );
            }

            $cartItem->update([
                'quantity' => $quantity,
                'question_answers' => $storedQuestionAnswers === [] ? null : $storedQuestionAnswers,
                'reminder_sent_at' => null,
            ]);

            return $cartItem->refresh();
        });
    }
}
