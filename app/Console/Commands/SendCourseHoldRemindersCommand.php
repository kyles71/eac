<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Mail\SendCourseHoldEmail;
use App\Models\CourseHold;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('course-holds:send-reminders')]
#[Description('Send reminders for class holds expiring within 24 hours')]
final class SendCourseHoldRemindersCommand extends Command
{
    public function handle(SendCourseHoldEmail $sendEmail): int
    {
        $sent = 0;

        CourseHold::query()
            ->whereNull('reminder_sent_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDay())
            ->whereHas('seats', fn (Builder $query): Builder => $query->available())
            ->with(['user', 'seats.course', 'seats.enrollment'])
            ->lazyById()
            ->each(function (CourseHold $hold) use ($sendEmail, &$sent): void {
                if ($hold->created_at->gt($hold->expires_at->copy()->subDay())) {
                    return;
                }

                if (! $sendEmail->handle($hold, 'course-hold-expiring')) {
                    return;
                }

                $hold->update(['reminder_sent_at' => now()]);
                $sent++;
            });

        $this->info("Sent {$sent} class hold reminder(s).");

        return self::SUCCESS;
    }
}
