<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class CourseHoldSeat extends Model
{
    /** @use HasFactory<\Database\Factories\CourseHoldSeatFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'course_hold_id' => 'integer',
        'course_id' => 'integer',
        'student_id' => 'integer',
        'locked_unit_price' => 'integer',
        'claimed_order_item_id' => 'integer',
        'released_at' => 'datetime',
        'released_by_user_id' => 'integer',
    ];

    /** @return BelongsTo<CourseHold, $this> */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(CourseHold::class, 'course_hold_id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function claimedOrderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'claimed_order_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    /** @return HasOne<Enrollment, $this> */
    public function enrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class);
    }

    /** @param Builder<CourseHoldSeat> $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->whereNull('released_at')
            ->whereNull('claimed_order_item_id')
            ->whereDoesntHave('enrollment')
            ->whereHas('hold', fn (Builder $query): Builder => $query->where('expires_at', '>', now()));
    }

    /** @param Builder<CourseHoldSeat> $query */
    public function scopeClaimable(Builder $query): void
    {
        $query->available();
    }

    /** @param Builder<CourseHoldSeat> $query */
    public function scopeReservingCapacity(Builder $query): void
    {
        $query->whereNull('released_at')
            ->whereDoesntHave('enrollment')
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('hold', fn (Builder $query): Builder => $query->where('expires_at', '>', now()))
                    ->orWhereHas('claimedOrderItem.order', fn (Builder $query): Builder => $query
                        ->whereIn('status', [OrderStatus::Pending, OrderStatus::Processing]));
            });
    }

    public function formattedLockedPrice(): string
    {
        return format_money($this->locked_unit_price);
    }
}
