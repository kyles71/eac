<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Mail\SendOpenEnrollmentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('enrollments:send-open-reminders')]
#[Description('Send reminders for enrollments that still need a student assigned')]
final class SendOpenEnrollmentRemindersCommand extends Command
{
    public function handle(SendOpenEnrollmentReminders $reminders): int
    {
        $result = $reminders->handle();

        $this->info("Reminded {$result['users_reminded']} user(s) about {$result['enrollments_marked']} open enrollment(s).");

        return self::SUCCESS;
    }
}
