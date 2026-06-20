<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Store\SendPastDueInstallmentNotification;
use App\Models\Installment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('installments:send-past-due-notifications')]
#[Description('Send administrator notifications for newly past-due payment plan installments')]
final class SendPastDueInstallmentNotificationsCommand extends Command
{
    public function handle(SendPastDueInstallmentNotification $notification): int
    {
        $queued = 0;

        Installment::query()
            ->overdue()
            ->whereNull('past_due_notification_sent_at')
            ->with('paymentPlan.order.user')
            ->lazyById()
            ->each(function (Installment $installment) use ($notification, &$queued): void {
                try {
                    if ($notification->handle($installment)) {
                        $queued++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        $this->info("Queued {$queued} past-due installment notification(s).");

        return self::SUCCESS;
    }
}
