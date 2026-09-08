<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventSubstituteRequestReason;
use App\Enums\EventSubstituteRequestStatus;
use Database\Factories\EventSubstituteRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventSubstituteRequest extends Model
{
    /** @use HasFactory<EventSubstituteRequestFactory> */
    use HasFactory;

    /** @var array<string, string> */
    protected $casts = [
        'event_id' => 'integer',
        'event_substitute_coverage_id' => 'integer',
        'teacher_id' => 'integer',
        'requested_by_user_id' => 'integer',
        'sick_instructor_id' => 'integer',
        'response_recorded_by_user_id' => 'integer',
        'status' => EventSubstituteRequestStatus::class,
        'reason_type' => EventSubstituteRequestReason::class,
        'responded_at' => 'datetime',
        'reminder_processed_at' => 'datetime',
        'release_requested_at' => 'datetime',
        'closed_at' => 'datetime',
        'closed_by_user_id' => 'integer',
    ];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<EventSubstituteCoverage, $this> */
    public function coverage(): BelongsTo
    {
        return $this->belongsTo(EventSubstituteCoverage::class, 'event_substitute_coverage_id');
    }

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sickInstructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sick_instructor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function responseRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'response_recorded_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', EventSubstituteRequestStatus::Pending);
    }

    public function isPending(): bool
    {
        return $this->status === EventSubstituteRequestStatus::Pending;
    }

    public function hasReleaseRequest(): bool
    {
        return $this->release_requested_at !== null;
    }
}
