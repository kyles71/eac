<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Mail\SendRecurringPrivateLessonBillingSummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('private-lessons:send-billing-summary {--force : Send regardless of the current date}')]
#[Description('Send the next-month recurring private lesson billing summary seven days before month-end')]
final class SendRecurringPrivateLessonBillingSummaryCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SendRecurringPrivateLessonBillingSummary $sendSummary): int
    {
        $result = $sendSummary->handle(force: (bool) $this->option('force'));

        if ($result['email_queued']) {
            $this->info("Queued the billing summary for {$result['lessons']} scheduled lesson(s).");
        } else {
            $this->info('No recurring private lesson billing summary was queued.');
        }

        return self::SUCCESS;
    }
}
