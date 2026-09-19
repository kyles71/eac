<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Store\SendInstallmentPaymentAttemptEmail;
use App\Enums\InstallmentPaymentAttemptEmailStatus;
use App\Models\InstallmentPaymentAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class SendInstallmentPaymentAttemptResultEmail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 900;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public int $paymentAttemptId,
        public string $paymentAttemptIdempotencyKey,
        public bool $successful,
    ) {}

    public function uniqueId(): string
    {
        return $this->paymentAttemptIdempotencyKey.':'.($this->successful ? 'success' : 'failure');
    }

    public function handle(SendInstallmentPaymentAttemptEmail $paymentEmail): void
    {
        $statusColumn = $this->statusColumn();
        $processingAtColumn = $this->processingAtColumn();
        $sentAtColumn = $this->sentAtColumn();
        $claimed = InstallmentPaymentAttempt::query()
            ->whereKey($this->paymentAttemptId)
            ->where($statusColumn, InstallmentPaymentAttemptEmailStatus::Pending)
            ->update([
                $statusColumn => InstallmentPaymentAttemptEmailStatus::Processing,
                $processingAtColumn => now(),
            ]) === 1;

        if (! $claimed) {
            return;
        }

        try {
            $paymentAttempt = InstallmentPaymentAttempt::query()
                ->with(['allocations.installment', 'paymentPlan.order.user'])
                ->findOrFail($this->paymentAttemptId);
            $queued = $paymentEmail->handle($paymentAttempt, $this->successful);
        } catch (Throwable $exception) {
            InstallmentPaymentAttempt::query()
                ->whereKey($this->paymentAttemptId)
                ->where($statusColumn, InstallmentPaymentAttemptEmailStatus::Processing)
                ->update([
                    $statusColumn => InstallmentPaymentAttemptEmailStatus::Pending,
                    $processingAtColumn => null,
                ]);

            throw $exception;
        }

        InstallmentPaymentAttempt::query()
            ->whereKey($this->paymentAttemptId)
            ->where($statusColumn, InstallmentPaymentAttemptEmailStatus::Processing)
            ->update([
                $statusColumn => $queued
                    ? InstallmentPaymentAttemptEmailStatus::Queued
                    : InstallmentPaymentAttemptEmailStatus::Skipped,
                $processingAtColumn => null,
                $sentAtColumn => $queued ? now() : null,
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        InstallmentPaymentAttempt::query()
            ->whereKey($this->paymentAttemptId)
            ->whereIn($this->statusColumn(), [
                InstallmentPaymentAttemptEmailStatus::Pending,
                InstallmentPaymentAttemptEmailStatus::Processing,
            ])
            ->update([
                $this->statusColumn() => InstallmentPaymentAttemptEmailStatus::Failed,
                $this->processingAtColumn() => null,
            ]);
    }

    private function statusColumn(): string
    {
        return $this->successful ? 'success_email_status' : 'failure_email_status';
    }

    private function sentAtColumn(): string
    {
        return $this->successful ? 'success_email_sent_at' : 'failure_email_sent_at';
    }

    private function processingAtColumn(): string
    {
        return $this->successful ? 'success_email_processing_at' : 'failure_email_processing_at';
    }
}
