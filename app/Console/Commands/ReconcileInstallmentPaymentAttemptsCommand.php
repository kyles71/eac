<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Store\ReconcileInstallmentPaymentAttempts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('installments:reconcile-payment-attempts')]
#[Description('Reconcile interrupted and asynchronous installment payment attempts')]
final class ReconcileInstallmentPaymentAttemptsCommand extends Command
{
    public function handle(ReconcileInstallmentPaymentAttempts $reconcilePaymentAttempts): int
    {
        $result = $reconcilePaymentAttempts->handle();

        $this->info("Reconciled {$result['processed']} payment attempt(s), queued {$result['emails_queued']} result email job(s); {$result['failed']} failed.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
