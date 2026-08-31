<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Mail\SendRecurringPrivateLessonPaymentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('private-lessons:send-payment-reminders')]
#[Description('Send seven-day and two-day recurring private lesson payment reminders')]
final class SendRecurringPrivateLessonPaymentRemindersCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SendRecurringPrivateLessonPaymentReminders $sendReminders): int
    {
        $result = $sendReminders->handle();
        $this->info("Processed {$result['charges_processed']} charge(s) and queued {$result['emails_queued']} email(s).");

        return self::SUCCESS;
    }
}
