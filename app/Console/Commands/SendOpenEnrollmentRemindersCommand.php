<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Mail\SendOpenEnrollmentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('enrollments:send-open-reminders')]
#[Description('Send reminders for enrollments that still need a student assigned')]
final class SendOpenEnrollmentRemindersCommand extends Command
{
    public function handle(SendOpenEnrollmentReminders $reminders): int
    {
        $result = $reminders->handle();

        $userLabel = Str::plural('user', $result['users_reminded']);
        $enrollmentLabel = Str::plural('enrollment', $result['enrollments_marked']);

        $this->info("Reminded {$result['users_reminded']} {$userLabel} about {$result['enrollments_marked']} open {$enrollmentLabel}.");

        return self::SUCCESS;
    }
}
