<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallmentStatus;
use App\Enums\OrderRefundStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Installment extends Model
{
    /** @use HasFactory<\Database\Factories\InstallmentFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'payment_plan_id' => 'integer',
        'installment_number' => 'integer',
        'amount' => 'integer',
        'due_date' => 'date',
        'status' => InstallmentStatus::class,
        'paid_at' => 'datetime',
        'retry_count' => 'integer',
        'last_attempted_at' => 'datetime',
        'past_due_notification_sent_at' => 'datetime',
    ];

    /** @return BelongsTo<PaymentPlan, $this> */
    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    /** @return HasMany<InstallmentDueDateAdjustment, $this> */
    public function dueDateAdjustments(): HasMany
    {
        return $this->hasMany(InstallmentDueDateAdjustment::class);
    }

    /**
     * Scope to installments that are due (due date <= today and still pending).
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', InstallmentStatus::Pending)
            ->whereDate('due_date', '<=', self::todayInDisplayTimezone());
    }

    /**
     * Scope to overdue installments.
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', InstallmentStatus::Overdue);
    }

    /**
     * Scope to installments that can be retried (failed with retry_count < 3).
     */
    public function scopeRetryable(Builder $query): void
    {
        $startOfToday = now()
            ->setTimezone((string) config('app.display_timezone', config('app.timezone')))
            ->startOfDay()
            ->setTimezone((string) config('app.timezone'));

        $query->where('status', InstallmentStatus::Failed)
            ->where('retry_count', '<', 3)
            ->whereDate('due_date', '<=', self::todayInDisplayTimezone())
            ->where(function (Builder $query) use ($startOfToday): void {
                $query
                    ->whereNull('last_attempted_at')
                    ->orWhere('last_attempted_at', '<', $startOfToday);
            });
    }

    public function scopeNotBlockedByRefundCancellation(Builder $query): void
    {
        $query->whereDoesntHave('paymentPlan.order.refunds', function (Builder $query): void {
            $query
                ->where('cancel_remaining_installments', true)
                ->whereIn('status', [
                    OrderRefundStatus::Processing,
                    OrderRefundStatus::Pending,
                    OrderRefundStatus::PartiallyFailed,
                ]);
        });
    }

    /**
     * Mark this installment as paid.
     */
    public function markPaid(?string $stripePaymentIntentId = null, ?string $stripeInvoiceId = null): void
    {
        $data = [
            'status' => InstallmentStatus::Paid,
            'paid_at' => now(),
            'last_attempted_at' => now(),
            'last_payment_status' => 'succeeded',
            'last_failure_reason' => null,
            'last_failure_code' => null,
        ];

        if ($stripePaymentIntentId !== null) {
            $data['stripe_payment_intent_id'] = $stripePaymentIntentId;
        }

        if ($stripeInvoiceId !== null) {
            $data['stripe_invoice_id'] = $stripeInvoiceId;
        }

        $this->update($data);
    }

    /**
     * Mark this installment as failed, incrementing the retry count.
     * If retry count reaches 3, mark as overdue instead.
     */
    public function markFailed(
        ?string $stripeStatus = 'failed',
        ?string $stripePaymentIntentId = null,
        ?string $failureReason = null,
        ?string $failureCode = null,
    ): void {
        $newRetryCount = $this->retry_count + 1;

        $this->update([
            'status' => $newRetryCount >= 3
                ? InstallmentStatus::Overdue
                : InstallmentStatus::Failed,
            'retry_count' => $newRetryCount,
            'last_attempted_at' => now(),
            'last_payment_status' => $stripeStatus,
            'last_failure_reason' => $failureReason,
            'last_failure_code' => $failureCode,
            'stripe_payment_intent_id' => $stripePaymentIntentId,
        ]);
    }

    private static function todayInDisplayTimezone(): string
    {
        return now()
            ->setTimezone((string) config('app.display_timezone', config('app.timezone')))
            ->toDateString();
    }
}
