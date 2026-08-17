<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RecurringPrivateLessonBillingPeriod extends Model
{
    /** @use HasFactory<\Database\Factories\RecurringPrivateLessonBillingPeriodFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'recurring_private_lesson_id' => 'integer',
        'period_start' => 'date',
        'last_billed_at' => 'datetime',
        'last_billed_by_user_id' => 'integer',
    ];

    /** @return BelongsTo<RecurringPrivateLesson, $this> */
    public function recurringPrivateLesson(): BelongsTo
    {
        return $this->belongsTo(RecurringPrivateLesson::class);
    }

    /** @return BelongsTo<User, $this> */
    public function lastBilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_billed_by_user_id');
    }

    /** @return HasMany<RecurringPrivateLessonCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(RecurringPrivateLessonCharge::class);
    }
}
