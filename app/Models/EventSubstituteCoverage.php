<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventSubstituteRequestStatus;
use Database\Factories\EventSubstituteCoverageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EventSubstituteCoverage extends Model
{
    /** @use HasFactory<EventSubstituteCoverageFactory> */
    use HasFactory;

    /** @var array<string, string> */
    protected $casts = [
        'event_id' => 'integer',
        'covered_teacher_id' => 'integer',
        'substitute_teacher_id' => 'integer',
        'needed_at' => 'datetime',
        'closed_at' => 'datetime',
        'closed_by_user_id' => 'integer',
    ];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function coveredTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'covered_teacher_id');
    }

    /** @return BelongsTo<User, $this> */
    public function substituteTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substitute_teacher_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** @return HasMany<EventSubstituteRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(EventSubstituteRequest::class);
    }

    public function pendingRequest(): ?EventSubstituteRequest
    {
        return $this->requests()
            ->where('status', EventSubstituteRequestStatus::Pending)
            ->latest('id')
            ->first();
    }

    public function currentSubstituteRequest(): ?EventSubstituteRequest
    {
        if ($this->substitute_teacher_id === null) {
            return null;
        }

        return $this->requests()
            ->where('teacher_id', $this->substitute_teacher_id)
            ->where('status', EventSubstituteRequestStatus::Accepted)
            ->latest('id')
            ->first();
    }

    public function isActive(): bool
    {
        return $this->needed_at !== null && $this->closed_at === null;
    }

    public function coveredTeacherName(): string
    {
        return $this->covered_teacher_id !== null
            ? $this->coveredTeacher->fullName
            : 'Original teacher not recorded';
    }

    public function substituteTeacherName(): ?string
    {
        return $this->substitute_teacher_id !== null
            ? $this->substituteTeacher->fullName
            : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNotNull('needed_at')
            ->whereNull('closed_at');
    }
}
