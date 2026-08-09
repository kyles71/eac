<?php

declare(strict_types=1);

namespace App\Actions\CourseHolds;

use App\Actions\Mail\SendCourseHoldEmail;
use App\Models\Course;
use App\Models\CourseHold;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class UpdateCourseHold
{
    public function __construct(private SendCourseHoldEmail $sendEmail) {}

    /** @param list<array{course_id: int, quantity: int}> $additionalLines */
    public function handle(
        CourseHold $hold,
        CarbonInterface $expiresAt,
        ?string $notes = null,
        array $additionalLines = [],
    ): CourseHold {
        if ($expiresAt->lte(now())) {
            throw new InvalidArgumentException('The hold expiration must be in the future.');
        }

        $updatedHold = DB::transaction(function () use ($hold, $expiresAt, $notes, $additionalLines): CourseHold {
            /** @var CourseHold|null $lockedHold */
            $lockedHold = CourseHold::query()->with('user')->lockForUpdate()->find($hold->id);

            if ($lockedHold === null) {
                throw new InvalidArgumentException('The class hold no longer exists.');
            }

            if ($lockedHold->expires_at->isPast()) {
                $this->ensureExpiredSeatsCanBeReactivated($lockedHold);
            }

            $expirationChanged = ! $lockedHold->expires_at->equalTo($expiresAt);
            $lockedHold->update([
                'expires_at' => $expiresAt,
                'notes' => filled($notes) ? $notes : null,
                'reminder_sent_at' => $expirationChanged ? null : $lockedHold->reminder_sent_at,
                'expired_email_sent_at' => $expirationChanged ? null : $lockedHold->expired_email_sent_at,
            ]);

            foreach (collect($additionalLines)->sortBy('course_id') as $line) {
                $courseId = (int) ($line['course_id'] ?? 0);
                $quantity = (int) ($line['quantity'] ?? 0);

                if ($courseId < 1 || $quantity < 1) {
                    throw new InvalidArgumentException('Each added class must have a valid class and quantity.');
                }

                /** @var Course|null $course */
                $course = Course::query()->with('product.productable')->lockForUpdate()->find($courseId);

                if ($course === null || $quantity > $course->getAvailableCapacity()) {
                    throw new InvalidArgumentException('One of the selected classes no longer has enough unreserved seats.');
                }

                $product = $course->product;

                if (! $product instanceof Product
                    || ! $product->canBePurchasedBy($lockedHold->user)
                    || ! is_int($product->price)
                    || $product->price <= 0) {
                    throw new InvalidArgumentException("\"{$course->name}\" is not currently purchasable by this family.");
                }

                $lockedPrice = $lockedHold->seats()
                    ->where('course_id', $course->id)
                    ->value('locked_unit_price') ?? $product->price;

                for ($index = 0; $index < $quantity; $index++) {
                    $lockedHold->seats()->create([
                        'course_id' => $course->id,
                        'locked_unit_price' => $lockedPrice,
                    ]);
                }
            }

            return $lockedHold->load(['user', 'seats.course.product', 'seats.student', 'seats.enrollment']);
        });

        $this->sendEmail->handle($updatedHold, 'course-hold-changed');

        return $updatedHold;
    }

    private function ensureExpiredSeatsCanBeReactivated(CourseHold $hold): void
    {
        $seatCountsByCourse = $hold->seats()
            ->whereNull('released_at')
            ->whereNull('claimed_order_item_id')
            ->whereDoesntHave('enrollment')
            ->selectRaw('course_id, count(*) as seat_count')
            ->groupBy('course_id')
            ->orderBy('course_id')
            ->pluck('seat_count', 'course_id');

        foreach ($seatCountsByCourse as $courseId => $seatCount) {
            /** @var Course|null $course */
            $course = Course::query()->lockForUpdate()->find((int) $courseId);

            if ($course === null || (int) $seatCount > $course->getAvailableCapacity()) {
                throw new InvalidArgumentException('An expired held class no longer has enough capacity to reactivate this hold.');
            }
        }
    }
}
