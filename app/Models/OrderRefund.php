<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderRefundStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OrderRefund extends Model
{
    /** @use HasFactory<\Database\Factories\OrderRefundFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'order_id' => 'integer',
        'processed_by_user_id' => 'integer',
        'amount' => 'integer',
        'cancel_remaining_installments' => 'boolean',
        'restore_store_credit' => 'boolean',
        'enrollment_ids' => 'array',
        'enrollment_details' => 'array',
        'status' => OrderRefundStatus::class,
        'completed_at' => 'datetime',
        'enrollments_removed_at' => 'datetime',
        'installments_cancelled_at' => 'datetime',
        'credit_restored_at' => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    /** @return HasMany<OrderRefundPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderRefundPayment::class)->orderBy('id');
    }

    public function formattedAmount(): string
    {
        return format_money($this->amount);
    }

    /** @return list<string> */
    public function additionalActionDescriptions(): array
    {
        $actions = array_values(array_filter([
            $this->cancel_remaining_installments ? 'Cancel remaining installments' : null,
            $this->restore_store_credit ? 'Restore applied store credit' : null,
        ]));
        $enrollmentAction = $this->enrollments_removed_at === null
            ? 'Remove enrollment'
            : 'Removed enrollment';
        $enrollmentDetails = $this->enrollment_details;
        $listedEnrollmentIds = [];

        if (is_array($enrollmentDetails)) {
            foreach ($enrollmentDetails as $details) {
                if (! is_array($details)
                    || ! is_int($details['id'] ?? null)
                    || ! is_string($details['student'] ?? null)
                    || ! is_string($details['course'] ?? null)) {
                    continue;
                }

                $actions[] = "{$enrollmentAction}: {$details['student']} — {$details['course']} (Enrollment #{$details['id']})";
                $listedEnrollmentIds[] = $details['id'];
            }
        }

        foreach ($this->enrollment_ids ?? [] as $enrollmentId) {
            if (is_int($enrollmentId) && ! in_array($enrollmentId, $listedEnrollmentIds, true)) {
                $actions[] = "{$enrollmentAction} #{$enrollmentId}";
            }
        }

        return $actions;
    }
}
