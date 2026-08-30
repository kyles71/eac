<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Mail\SendEventSubstituteRequestReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('events:send-substitute-request-reminders')]
#[Description('Remind teachers and requesters about unanswered event substitute requests')]
final class SendEventSubstituteRequestRemindersCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SendEventSubstituteRequestReminders $reminders): int
    {
        $result = $reminders->handle();

        $this->components->info(
            "Expired {$result['expired']} request(s), processed {$result['requests_processed']} reminder(s), and queued {$result['emails_queued']} email(s)."
        );

        return self::SUCCESS;
    }
}
