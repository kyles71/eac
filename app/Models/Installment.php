<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    /** @return BelongsTo<PaymentPlan, $this> */
    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    /**
     * Scope to installments that are due (due date <= today and still pending).
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', InstallmentStatus::Pending)
            ->whereDate('due_date', '<=', now());
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
        $query->where('status', InstallmentStatus::Failed)
            ->where('retry_count', '<', 3);
    }

    /**
     * Mark this installment as paid.
     */
    public function markPaid(?string $stripePaymentIntentId = null, ?string $stripeInvoiceId = null): void
    {
        $data = [
            'status' => InstallmentStatus::Paid,
            'paid_at' => now(),
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
    public function markFailed(): void
    {
        $newRetryCount = $this->retry_count + 1;

        if ($newRetryCount >= 3) {
            $this->update([
                'status' => InstallmentStatus::Overdue,
                'retry_count' => $newRetryCount,
            ]);
        } else {
            $this->update([
                'status' => InstallmentStatus::Failed,
                'retry_count' => $newRetryCount,
            ]);
        }
    }
}
