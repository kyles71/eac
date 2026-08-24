<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderRefundPaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrderRefundPayment extends Model
{
    /** @use HasFactory<\Database\Factories\OrderRefundPaymentFactory> */
    use HasFactory, HasUuids;

    protected $casts = [
        'id' => 'integer',
        'order_refund_id' => 'integer',
        'amount' => 'integer',
        'status' => OrderRefundPaymentStatus::class,
        'refunded_at' => 'datetime',
    ];

    /** @return BelongsTo<OrderRefund, $this> */
    public function orderRefund(): BelongsTo
    {
        return $this->belongsTo(OrderRefund::class);
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['idempotency_key'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function idempotencyKey(): string
    {
        return "order-refund-payment-{$this->idempotency_key}";
    }

    public function recordStripeStatus(
        ?string $stripeRefundId,
        OrderRefundPaymentStatus $status,
        ?string $failureReason = null,
    ): self {
        return DB::transaction(function () use ($stripeRefundId, $status, $failureReason): self {
            /** @var self $payment */
            $payment = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($payment->stripe_refund_id !== null
                && $stripeRefundId !== null
                && $payment->stripe_refund_id !== $stripeRefundId) {
                return $payment;
            }

            $attributes = [];

            if ($payment->stripe_refund_id === null && $stripeRefundId !== null) {
                $attributes['stripe_refund_id'] = $stripeRefundId;
            }

            if ($payment->status->canTransitionTo($status)) {
                $attributes['status'] = $status;
                $attributes['failure_reason'] = $failureReason;
                $attributes['refunded_at'] = $status === OrderRefundPaymentStatus::Succeeded
                    ? ($payment->refunded_at ?? now())
                    : null;
            }

            if ($attributes !== []) {
                $payment->update($attributes);
            }

            return $payment->refresh();
        }, attempts: 3);
    }
}
