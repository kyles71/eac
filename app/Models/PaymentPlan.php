<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentPlanFrequency;
use App\Enums\PaymentPlanMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PaymentPlan extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentPlanFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'order_id' => 'integer',
        'payment_plan_template_id' => 'integer',
        'method' => PaymentPlanMethod::class,
        'total_amount' => 'integer',
        'number_of_installments' => 'integer',
        'frequency' => PaymentPlanFrequency::class,
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<PaymentPlanTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PaymentPlanTemplate::class, 'payment_plan_template_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class);
    }

    /**
     * Check if all installments have been paid.
     */
    public function isFullyPaid(): bool
    {
        return $this->installments()
            ->where('status', '!=', \App\Enums\InstallmentStatus::Paid->value)
            ->doesntExist();
    }

    /**
     * Get the total amount paid so far.
     */
    public function amountPaid(): int
    {
        return (int) $this->installments()
            ->where('status', \App\Enums\InstallmentStatus::Paid->value)
            ->sum('amount');
    }

    /**
     * Get the remaining balance.
     */
    public function remainingBalance(): int
    {
        return $this->total_amount - $this->amountPaid();
    }
}
