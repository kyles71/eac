<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Enums\InstallmentPaymentAttemptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InstallmentPaymentAttempt extends Model
{
    /** @use HasFactory<\Database\Factories\InstallmentPaymentAttemptFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'payment_plan_id' => 'integer',
        'initiated_by_user_id' => 'integer',
        'origin' => InstallmentPaymentAttemptOrigin::class,
        'status' => InstallmentPaymentAttemptStatus::class,
        'total_amount' => 'integer',
        'use_for_future' => 'boolean',
        'failure_recorded_at' => 'datetime',
        'success_email_sent_at' => 'datetime',
        'failure_email_sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return BelongsTo<PaymentPlan, $this> */
    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** @return HasMany<InstallmentPaymentAttemptAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(InstallmentPaymentAttemptAllocation::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [
            InstallmentPaymentAttemptStatus::Pending,
            InstallmentPaymentAttemptStatus::RequiresPaymentMethod,
            InstallmentPaymentAttemptStatus::RequiresAction,
            InstallmentPaymentAttemptStatus::Processing,
        ]);
    }

    public function stripeIdempotencyKey(): string
    {
        return "installment-payment-attempt-{$this->idempotency_key}";
    }
}
