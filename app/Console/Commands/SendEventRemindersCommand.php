<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Mail\SendEventReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('events:send-reminders')]
#[Description('Send reminders for events occurring in two weeks')]
final class SendEventRemindersCommand extends Command
{
    public function handle(SendEventReminders $reminders): int
    {
        $result = $reminders->handle();

        $this->info("Processed {$result['events_processed']} event reminder(s) and queued {$result['emails_queued']} email(s).");

        return self::SUCCESS;
    }
}
