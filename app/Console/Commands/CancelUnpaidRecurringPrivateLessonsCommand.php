<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RecurringPrivateLessons\CancelUnpaidRecurringPrivateLessons;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('private-lessons:cancel-unpaid')]
#[Description('Cancel unpaid recurring private lessons when they reach the 24-hour cutoff')]
final class CancelUnpaidRecurringPrivateLessonsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CancelUnpaidRecurringPrivateLessons $cancelUnpaid): int
    {
        $count = $cancelUnpaid->handle();
        $this->info("Cancelled {$count} unpaid recurring private lesson(s).");

        return self::SUCCESS;
    }
}
