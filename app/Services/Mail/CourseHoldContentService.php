<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Filament\User\Pages\HeldClasses;
use App\Models\CourseHold;

final readonly class CourseHoldContentService
{
    /** @return array{tokens: array<string, string>, slots: array<string, string>} */
    public function for(CourseHold $hold): array
    {
        $hold->loadMissing(['user', 'seats.course', 'seats.enrollment']);
        $availableSeats = $hold->seats
            ->filter(fn ($seat): bool => $hold->expires_at->isFuture()
                && $seat->released_at === null
                && $seat->claimed_order_item_id === null
                && $seat->enrollment === null);

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'user.first_name' => $hold->user->first_name,
                'user.full_name' => $hold->user->displayName(),
                'user.email' => $hold->user->email,
                'course_hold.number' => (string) $hold->id,
                'course_hold.expires_at' => $hold->expires_at
                    ->timezone((string) config('app.display_timezone', config('app.timezone')))
                    ->format('F j, Y \a\t g:i A'),
                'course_hold.status' => $hold->status()->getLabel(),
                'course_hold.seat_count' => (string) $availableSeats->count(),
            ],
            'slots' => [
                'course-hold-details' => view('mail.course-hold-details', [
                    'hold' => $hold,
                    'seatGroups' => $availableSeats->groupBy('course_id'),
                    'purchaseUrl' => HeldClasses::getUrl(['hold' => $hold->id], panel: 'user'),
                ])->render(),
            ],
        ];
    }
}
