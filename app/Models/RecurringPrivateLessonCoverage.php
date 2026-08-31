<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecurringPrivateLessonCoverageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RecurringPrivateLessonCoverage extends Model
{
    /** @use HasFactory<\Database\Factories\RecurringPrivateLessonCoverageFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'recurring_private_lesson_charge_id' => 'integer',
        'order_item_id' => 'integer',
        'status' => RecurringPrivateLessonCoverageStatus::class,
        'gross_amount' => 'integer',
        'discount_amount' => 'integer',
        'restricted_credit_amount' => 'integer',
        'credit_amount' => 'integer',
        'stripe_amount' => 'integer',
        'stripe_refund_id' => 'string',
    ];

    /** @return BelongsTo<RecurringPrivateLessonCharge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(RecurringPrivateLessonCharge::class, 'recurring_private_lesson_charge_id');
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function netPaidAmount(): int
    {
        return min(
            $this->netLessonPrice(),
            $this->restricted_credit_amount + $this->credit_amount + $this->stripe_amount,
        );
    }

    public function restorableCreditAmount(): int
    {
        return min(
            $this->netLessonPrice(),
            $this->restricted_credit_amount + $this->credit_amount,
        );
    }

    public function refundableStripeAmount(): int
    {
        return min(
            $this->stripe_amount,
            max(0, $this->netLessonPrice() - $this->restorableCreditAmount()),
        );
    }

    private function netLessonPrice(): int
    {
        $this->loadMissing('charge');

        return max(0, $this->charge->amount - min($this->discount_amount, $this->charge->amount));
    }
}
