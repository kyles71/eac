<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\HasCapacity;
use App\Contracts\Productable;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonResolutionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use InvalidArgumentException;

final class RecurringPrivateLessonCharge extends Model implements HasCapacity, Productable
{
    /** @use HasFactory<\Database\Factories\RecurringPrivateLessonChargeFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'recurring_private_lesson_id' => 'integer',
        'recurring_private_lesson_billing_period_id' => 'integer',
        'event_id' => 'integer',
        'status' => RecurringPrivateLessonChargeStatus::class,
        'amount' => 'integer',
        'billed_at' => 'datetime',
        'billed_by_user_id' => 'integer',
        'seven_day_reminder_sent_at' => 'datetime',
        'two_day_reminder_sent_at' => 'datetime',
        'reschedule_history' => 'array',
        'resolved_at' => 'datetime',
        'resolved_by_user_id' => 'integer',
        'resolution_type' => RecurringPrivateLessonResolutionType::class,
        'automatically_cancelled_at' => 'datetime',
    ];

    /** @return BelongsTo<RecurringPrivateLesson, $this> */
    public function recurringPrivateLesson(): BelongsTo
    {
        return $this->belongsTo(RecurringPrivateLesson::class);
    }

    /** @return BelongsTo<RecurringPrivateLessonBillingPeriod, $this> */
    public function billingPeriod(): BelongsTo
    {
        return $this->belongsTo(
            RecurringPrivateLessonBillingPeriod::class,
            'recurring_private_lesson_billing_period_id',
        );
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function billedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /** @return MorphOne<Product, $this> */
    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'productable');
    }

    /** @return HasOne<RecurringPrivateLessonCoverage, $this> */
    public function coverage(): HasOne
    {
        return $this->hasOne(RecurringPrivateLessonCoverage::class);
    }

    public function getAvailableCapacity(?User $user = null): int
    {
        $this->loadMissing(['event', 'recurringPrivateLesson']);

        if ($this->status !== RecurringPrivateLessonChargeStatus::Billed
            || $this->event->isCancelled()
            || $this->event->start_time === null
            || ! $this->event->start_time->gt(now()->addDay())) {
            return 0;
        }

        if ($user !== null && $user->id !== $this->recurringPrivateLesson->user_id) {
            return 0;
        }

        return 1;
    }

    public function fulfillOrderItem(OrderItem $orderItem, User $purchaser): bool
    {
        $this->loadMissing(['event', 'recurringPrivateLesson']);

        if ($purchaser->id !== $this->recurringPrivateLesson->user_id
            || $orderItem->quantity !== 1
            || $this->getAvailableCapacity($purchaser) !== 1) {
            throw new InvalidArgumentException('This recurring private lesson is no longer available for payment.');
        }

        $this->coverage()->create([
            'order_item_id' => $orderItem->id,
            'gross_amount' => $orderItem->total_price,
            'discount_amount' => $orderItem->discount_allocated,
            'restricted_credit_amount' => $orderItem->restricted_credit_allocated,
            'credit_amount' => $orderItem->credit_allocated,
            'stripe_amount' => $orderItem->stripe_allocated,
        ]);

        $this->update(['status' => RecurringPrivateLessonChargeStatus::Paid]);
        $this->product()->update(['is_active' => false]);

        return true;
    }

    public function formattedAmount(): string
    {
        return format_money($this->amount);
    }
}
