<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\InstallmentPaymentAttemptStatus;
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

    /** @return array{processed: int, failed: int} */
    public function handle(): array
    {
        $processed = 0;
        $failed = 0;

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

                    if ($paymentAttempt->status === InstallmentPaymentAttemptStatus::RequiresPaymentMethod
                        && $paymentAttempt->created_at->lte(now()->subDay())
                        && $user instanceof User) {
                        $this->cancelPaymentAttempt->handle($paymentAttempt, $user);
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                }
            });

        return compact('processed', 'failed');
    }
}
