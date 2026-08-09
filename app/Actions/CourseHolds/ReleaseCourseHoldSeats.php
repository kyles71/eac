<?php

declare(strict_types=1);

namespace App\Actions\CourseHolds;

use App\Actions\Mail\SendCourseHoldEmail;
use App\Models\CourseHold;
use App\Models\CourseHoldSeat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ReleaseCourseHoldSeats
{
    public function __construct(private SendCourseHoldEmail $sendEmail) {}

    /** @param list<int>|null $seatIds */
    public function handle(CourseHold $hold, User $releasedBy, ?array $seatIds = null): int
    {
        $released = DB::transaction(function () use ($hold, $releasedBy, $seatIds): int {
            $query = CourseHoldSeat::query()
                ->where('course_hold_id', $hold->id)
                ->whereNull('released_at')
                ->whereNull('claimed_order_item_id')
                ->whereDoesntHave('enrollment')
                ->lockForUpdate();

            if ($seatIds !== null) {
                $query->whereKey($seatIds);
            }

            $seats = $query->get();

            if ($seats->isEmpty()) {
                throw new InvalidArgumentException('No unpurchased and unclaimed seats are available to release.');
            }

            return CourseHoldSeat::query()
                ->whereKey($seats->modelKeys())
                ->update([
                    'released_at' => now(),
                    'released_by_user_id' => $releasedBy->id,
                    'updated_at' => now(),
                ]);
        });

        $this->sendEmail->handle(
            $hold->refresh()->load(['user', 'seats.course.product', 'seats.student', 'seats.enrollment']),
            'course-hold-changed',
        );

        return $released;
    }
}
