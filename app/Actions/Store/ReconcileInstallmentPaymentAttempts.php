<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\InstallmentPaymentAttemptEmailStatus;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Jobs\SendInstallmentPaymentAttemptResultEmail;
use App\Models\InstallmentPaymentAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final readonly class ReconcileInstallmentPaymentAttempts
{
    public function __construct(
        private ProcessInstallmentPaymentAttempt $processPaymentAttempt,
        private CancelInstallmentPaymentAttempt $cancelPaymentAttempt,
    ) {}

    /** @return array{processed: int, failed: int, emails_queued: int} */
    public function handle(): array
    {
        $processed = 0;
        $failed = 0;
        $emailsQueued = 0;

        $this->releaseStaleEmailClaims();

        InstallmentPaymentAttempt::query()
            ->active()
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->whereIn('status', [
                                InstallmentPaymentAttemptStatus::Pending,
                                InstallmentPaymentAttemptStatus::RequiresAction,
                                InstallmentPaymentAttemptStatus::Processing,
                            ])
                            ->where('updated_at', '<=', now()->subMinutes(2));
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('status', InstallmentPaymentAttemptStatus::RequiresPaymentMethod)
                            ->where('updated_at', '<=', now()->subHour());
                    });
            })
            ->with(['paymentPlan.order.user', 'allocations.installment'])
            ->lazyById()
            ->each(function (InstallmentPaymentAttempt $paymentAttempt) use (&$processed, &$failed): void {
                $processed++;

                try {
                    $paymentAttempt = $this->processPaymentAttempt->handle($paymentAttempt);
                    $user = $paymentAttempt->paymentPlan->order?->user;

                    if (in_array($paymentAttempt->status, [
                        InstallmentPaymentAttemptStatus::RequiresPaymentMethod,
                        InstallmentPaymentAttemptStatus::RequiresAction,
                    ], true)
                        && $paymentAttempt->created_at->lte(now()->subDay())
                        && $user instanceof User) {
                        $this->cancelPaymentAttempt->handle($paymentAttempt, $user);
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                }
            });

        InstallmentPaymentAttempt::query()
            ->where(function (Builder $query): void {
                $query
                    ->where('success_email_status', InstallmentPaymentAttemptEmailStatus::Pending)
                    ->orWhere('failure_email_status', InstallmentPaymentAttemptEmailStatus::Pending);
            })
            ->lazyById()
            ->each(function (InstallmentPaymentAttempt $paymentAttempt) use (&$emailsQueued, &$failed): void {
                foreach ([true, false] as $successful) {
                    $status = $successful
                        ? $paymentAttempt->success_email_status
                        : $paymentAttempt->failure_email_status;

                    if ($status !== InstallmentPaymentAttemptEmailStatus::Pending) {
                        continue;
                    }

                    try {
                        SendInstallmentPaymentAttemptResultEmail::dispatch(
                            $paymentAttempt->id,
                            $paymentAttempt->idempotency_key,
                            $successful,
                        )
                            ->afterCommit();
                        $emailsQueued++;
                    } catch (Throwable $exception) {
                        $failed++;
                        report($exception);
                    }
                }
            });

        return [
            'processed' => $processed,
            'failed' => $failed,
            'emails_queued' => $emailsQueued,
        ];
    }

    private function releaseStaleEmailClaims(): void
    {
        foreach (['success', 'failure'] as $result) {
            InstallmentPaymentAttempt::query()
                ->where("{$result}_email_status", InstallmentPaymentAttemptEmailStatus::Processing)
                ->where("{$result}_email_processing_at", '<=', now()->subMinutes(15))
                ->update([
                    "{$result}_email_status" => InstallmentPaymentAttemptEmailStatus::Pending,
                    "{$result}_email_processing_at" => null,
                ]);
        }
    }
}
