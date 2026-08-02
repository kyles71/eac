<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseHoldStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CourseHold extends Model
{
    /** @use HasFactory<\Database\Factories\CourseHoldFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'created_by_user_id' => 'integer',
        'expires_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<CourseHoldSeat, $this> */
    public function seats(): HasMany
    {
        return $this->hasMany(CourseHoldSeat::class);
    }

    /** @param Builder<CourseHold> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('expires_at', '>', now())
            ->whereHas('seats', fn (Builder $query): Builder => $query->available());
    }

    public function status(): CourseHoldStatus
    {
        $this->loadMissing('seats.enrollment');

        $purchased = $this->seats->filter(fn (CourseHoldSeat $seat): bool => $seat->enrollment !== null)->count();
        $released = $this->seats->whereNotNull('released_at')->count();
        $total = $this->seats->count();

        if ($total > 0 && $purchased === $total) {
            return CourseHoldStatus::Purchased;
        }

        if ($total > 0 && $released === $total) {
            return CourseHoldStatus::Released;
        }

        if ($this->expires_at->isPast() && $purchased < $total) {
            return CourseHoldStatus::Expired;
        }

        if ($purchased > 0) {
            return CourseHoldStatus::PartiallyPurchased;
        }

        return CourseHoldStatus::Active;
    }

    public function availableSeatCount(): int
    {
        return $this->seats()->available()->count();
    }

    public function displayName(): string
    {
        return 'Hold #'.$this->id.' for '.$this->user->displayName();
    }
}
