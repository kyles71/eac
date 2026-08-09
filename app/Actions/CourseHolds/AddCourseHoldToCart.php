<?php

declare(strict_types=1);

namespace App\Actions\CourseHolds;

use App\Models\CartItem;
use App\Models\CourseHold;
use App\Models\CourseHoldSeat;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductAvailabilityService;
use App\Services\ProductQuestionAnswerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class AddCourseHoldToCart
{
    public function __construct(private ProductQuestionAnswerService $questionAnswers) {}

    /**
     * @param  array<int, int>  $quantitiesByCourseId  Empty means all available seats.
     * @param  array<int, array<int|string, mixed>>  $questionAnswersByCourseId
     * @return Collection<int, CartItem>
     */
    public function handle(
        User $user,
        CourseHold $hold,
        array $quantitiesByCourseId = [],
        array $questionAnswersByCourseId = [],
    ): Collection {
        return DB::transaction(function () use ($user, $hold, $quantitiesByCourseId, $questionAnswersByCourseId): Collection {
            /** @var CourseHold|null $lockedHold */
            $lockedHold = CourseHold::query()
                ->where('user_id', $user->id)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->find($hold->id);

            if ($lockedHold === null) {
                throw new InvalidArgumentException('This class hold has expired or is unavailable.');
            }

            $seats = CourseHoldSeat::query()
                ->with('course.product.productable')
                ->where('course_hold_id', $lockedHold->id)
                ->claimable()
                ->lockForUpdate()
                ->get()
                ->groupBy('course_id');

            if ($seats->isEmpty()) {
                throw new InvalidArgumentException('This hold has no seats available to add to the cart.');
            }

            $cartItems = collect();

            foreach ($seats as $courseId => $courseSeats) {
                if ($quantitiesByCourseId !== [] && ! array_key_exists((int) $courseId, $quantitiesByCourseId)) {
                    continue;
                }

                $quantity = $quantitiesByCourseId === []
                    ? $courseSeats->count()
                    : (int) $quantitiesByCourseId[(int) $courseId];

                if ($quantity < 1 || $quantity > $courseSeats->count()) {
                    throw new InvalidArgumentException('A held-seat quantity is no longer available.');
                }

                /** @var CourseHoldSeat $firstSeat */
                $firstSeat = $courseSeats->first();
                $product = $firstSeat->course->product;

                if (! $product instanceof Product
                    || ! app(ProductAvailabilityService::class)->resultFor($product, $user)->isPurchasable()) {
                    throw new InvalidArgumentException("\"{$firstSeat->course->name}\" is no longer available for purchase.");
                }

                $lockedPrices = $courseSeats->pluck('locked_unit_price')->unique();

                if ($lockedPrices->count() !== 1) {
                    throw new InvalidArgumentException('Held seats with different locked prices must be placed in separate holds.');
                }

                $attributes = [
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'course_hold_id' => $lockedHold->id,
                    'custom_gift_card_amount' => 0,
                ];
                $values = [
                    'quantity' => $quantity,
                    'held_unit_price' => (int) $lockedPrices->first(),
                    'reminder_sent_at' => null,
                ];

                if (array_key_exists((int) $courseId, $questionAnswersByCourseId)) {
                    $normalizedAnswers = $this->questionAnswers->normalizeUnits(
                        $product,
                        $questionAnswersByCourseId[(int) $courseId],
                        $quantity,
                        totalQuantity: $quantity,
                    );
                    $values['question_answers'] = $normalizedAnswers === [] ? null : $normalizedAnswers;
                }

                /** @var CartItem $cartItem */
                $cartItem = CartItem::query()->updateOrCreate($attributes, $values);

                $cartItems->push($cartItem->refresh());
            }

            if ($cartItems->isEmpty()) {
                throw new InvalidArgumentException('Select at least one held class to add to the cart.');
            }

            return $cartItems;
        });
    }
}
