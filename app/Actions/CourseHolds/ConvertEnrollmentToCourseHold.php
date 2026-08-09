<?php

declare(strict_types=1);

namespace App\Actions\CourseHolds;

use App\Actions\Mail\SendCourseHoldEmail;
use App\Models\CourseHold;
use App\Models\Enrollment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ConvertEnrollmentToCourseHold
{
    public function __construct(private SendCourseHoldEmail $sendEmail) {}

    public function handle(
        Enrollment $enrollment,
        CarbonInterface $expiresAt,
        ?User $createdBy = null,
        ?string $notes = null,
    ): CourseHold {
        if ($expiresAt->lte(now())) {
            throw new InvalidArgumentException('The hold expiration must be in the future.');
        }

        $hold = DB::transaction(function () use ($enrollment, $expiresAt, $createdBy, $notes): CourseHold {
            /** @var Enrollment|null $lockedEnrollment */
            $lockedEnrollment = Enrollment::query()
                ->with(['course.product.productable', 'user', 'student'])
                ->lockForUpdate()
                ->find($enrollment->id);

            if ($lockedEnrollment === null || $lockedEnrollment->order_item_id !== null) {
                throw new InvalidArgumentException('Only a manual enrollment can be converted into a hold.');
            }

            $product = $lockedEnrollment->course->product;

            if (! $product instanceof Product
                || ! $product->canBePurchasedBy($lockedEnrollment->user)
                || ! is_int($product->price)
                || $product->price <= 0) {
                throw new InvalidArgumentException('This enrollment does not have a product the family can purchase.');
            }

            /** @var CourseHold $hold */
            $hold = CourseHold::query()->create([
                'user_id' => $lockedEnrollment->user_id,
                'created_by_user_id' => $createdBy?->id,
                'expires_at' => $expiresAt,
                'notes' => filled($notes) ? $notes : null,
            ]);

            $hold->seats()->create([
                'course_id' => $lockedEnrollment->course_id,
                'student_id' => $lockedEnrollment->student_id,
                'locked_unit_price' => $product->price,
            ]);

            $lockedEnrollment->delete();

            return $hold->load(['user', 'seats.course.product', 'seats.student']);
        });

        $this->sendEmail->handle($hold, 'course-hold-created');

        return $hold;
    }
}
