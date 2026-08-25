<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RecurringPrivateLesson extends Model
{
    /** @use HasFactory<\Database\Factories\RecurringPrivateLessonFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'course_id' => 'integer',
        'user_id' => 'integer',
        'student_id' => 'integer',
        'lesson_price' => 'integer',
        'status' => RecurringPrivateLessonStatus::class,
    ];

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return HasMany<RecurringPrivateLessonBillingPeriod, $this> */
    public function billingPeriods(): HasMany
    {
        return $this->hasMany(RecurringPrivateLessonBillingPeriod::class);
    }

    /** @return HasMany<RecurringPrivateLessonCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(RecurringPrivateLessonCharge::class);
    }

    /** @return HasMany<RecurringPrivateLessonCharge, $this> */
    public function scheduledCharges(): HasMany
    {
        return $this->charges()
            ->where('status', RecurringPrivateLessonChargeStatus::Scheduled);
    }

    public function nextUnbilledLessonAt(): ?CarbonInterface
    {
        $this->loadMissing('scheduledCharges.event');

        return $this->scheduledCharges
            ->filter(fn (RecurringPrivateLessonCharge $charge): bool => $charge->event->start_time?->isFuture() === true
                && ! $charge->event->isCancelled())
            ->sortBy('event.start_time')
            ->first()?->event->start_time;
    }

    public function formattedLessonPrice(): string
    {
        return format_money($this->lesson_price);
    }
}
