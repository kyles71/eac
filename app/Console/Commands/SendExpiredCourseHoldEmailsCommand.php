<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Mail\SendCourseHoldEmail;
use App\Models\CourseHold;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('course-holds:send-expired-emails')]
#[Description('Notify families when class holds expire with unpurchased seats')]
final class SendExpiredCourseHoldEmailsCommand extends Command
{
    public function handle(SendCourseHoldEmail $sendEmail): int
    {
        $sent = 0;

        CourseHold::query()
            ->whereNull('expired_email_sent_at')
            ->where('expires_at', '<=', now())
            ->whereHas('seats', fn (Builder $query): Builder => $query
                ->whereNull('released_at')
                ->whereNull('claimed_order_item_id')
                ->whereDoesntHave('enrollment'))
            ->with(['user', 'seats.course', 'seats.enrollment'])
            ->lazyById()
            ->each(function (CourseHold $hold) use ($sendEmail, &$sent): void {
                if (! $sendEmail->handle($hold, 'course-hold-expired')) {
                    return;
                }

                $hold->update(['expired_email_sent_at' => now()]);
                $sent++;
            });

        $this->info("Sent {$sent} expired class hold email(s).");

        return self::SUCCESS;
    }
}
