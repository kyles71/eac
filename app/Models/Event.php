<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventSubstituteCoverageStatus;
use App\Enums\EventSubstituteRequestStatus;
use App\Support\MediaDisks;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Tags\Tag;

final class Event extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /** @var array<string, string> */
    protected $casts = [
        'id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'course_id' => 'integer',
        'substitute_teacher_id' => 'integer',
        'substitute_needed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'cancelled_by_user_id' => 'integer',
        'reminder_processed_at' => 'datetime',
    ];

    public static function applyAdminAccessConstraint(Builder $query, User $user): Builder
    {
        if (! $user->hasCourseRestrictedAdminAccess()) {
            return $query;
        }

        return $query->whereHas(
            'course.teachers',
            fn (Builder $query): Builder => $query->whereKey($user->id),
        );
    }

    public static function applyAdminViewAccessConstraint(Builder $query, User $user): Builder
    {
        if (! $user->hasCourseRestrictedAdminAccess()) {
            return $query;
        }

        return self::applyAdminUserViewConstraint($query, $user);
    }

    public static function applyAdminUserViewConstraint(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query
                ->where('substitute_teacher_id', $user->id)
                ->orWhereHas(
                    'course.teachers',
                    fn (Builder $query): Builder => $query->whereKey($user->id),
                );
        });
    }

    public static function applyNotPassedConstraint(Builder $query, ?CarbonInterface $dateTime = null): Builder
    {
        $dateTime ??= now();

        return $query->where(function (Builder $query) use ($dateTime): void {
            $query
                ->where('end_time', '>=', $dateTime)
                ->orWhere(function (Builder $query) use ($dateTime): void {
                    $query
                        ->whereNull('end_time')
                        ->where('start_time', '>=', $dateTime);
                });
        });
    }

    public static function applyPassedConstraint(Builder $query, ?CarbonInterface $dateTime = null): Builder
    {
        $dateTime ??= now();

        return $query->where(function (Builder $query) use ($dateTime): void {
            $query
                ->where(function (Builder $query) use ($dateTime): void {
                    $query
                        ->whereNotNull('end_time')
                        ->where('end_time', '<', $dateTime);
                })
                ->orWhere(function (Builder $query) use ($dateTime): void {
                    $query
                        ->whereNull('end_time')
                        ->where('start_time', '<', $dateTime);
                });
        });
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /** @return HasOne<RecurringPrivateLessonCharge, $this> */
    public function recurringPrivateLessonCharge(): HasOne
    {
        return $this->hasOne(RecurringPrivateLessonCharge::class);
    }

    /** @return BelongsTo<User, $this> */
    public function substituteTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substitute_teacher_id');
    }

    /** @return HasMany<EventSubstituteRequest, $this> */
    public function substituteRequests(): HasMany
    {
        return $this->hasMany(EventSubstituteRequest::class);
    }

    public function pendingSubstituteRequest(): ?EventSubstituteRequest
    {
        return $this->substituteRequests()
            ->where('status', EventSubstituteRequestStatus::Pending)
            ->latest('id')
            ->first();
    }

    public function currentSubstituteRequest(): ?EventSubstituteRequest
    {
        if ($this->substitute_teacher_id === null) {
            return null;
        }

        return $this->substituteRequests()
            ->where('teacher_id', $this->substitute_teacher_id)
            ->where('status', EventSubstituteRequestStatus::Accepted)
            ->latest('id')
            ->first();
    }

    public function substituteCoverageStatus(): EventSubstituteCoverageStatus
    {
        $currentRequest = $this->currentSubstituteRequest();
        $pendingRequest = $this->pendingSubstituteRequest();

        if ($currentRequest?->hasReleaseRequest()) {
            return EventSubstituteCoverageStatus::ReleaseRequested;
        }

        if ($this->substitute_teacher_id !== null && $pendingRequest instanceof EventSubstituteRequest) {
            return EventSubstituteCoverageStatus::ReplacementPending;
        }

        if ($this->substitute_teacher_id !== null) {
            return EventSubstituteCoverageStatus::Confirmed;
        }

        if ($pendingRequest instanceof EventSubstituteRequest) {
            return EventSubstituteCoverageStatus::AwaitingResponse;
        }

        return $this->substitute_needed_at !== null
            ? EventSubstituteCoverageStatus::NeedsSubstitute
            : EventSubstituteCoverageStatus::NotNeeded;
    }

    public function substituteResponseCutoff(): ?CarbonInterface
    {
        return $this->end_time ?? $this->start_time;
    }

    public function canAcceptSubstituteRequestAt(?CarbonInterface $dateTime = null): bool
    {
        if ($this->isCancelled() || $this->substituteResponseCutoff() === null) {
            return false;
        }

        return ($dateTime ?? now())->lt($this->substituteResponseCutoff());
    }

    public function isCompletedAt(?CarbonInterface $dateTime = null): bool
    {
        $cutoff = $this->substituteResponseCutoff();

        return $cutoff !== null && ($dateTime ?? now())->gte($cutoff);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function canBeCancelledAt(?CarbonInterface $dateTime = null): bool
    {
        if ($this->isCancelled()) {
            return false;
        }

        $dateTime ??= now();

        if ($this->end_time !== null) {
            return $dateTime->lt($this->end_time);
        }

        return $this->start_time !== null && $dateTime->lt($this->start_time);
    }

    /** @return HasMany<EventAttendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }

    /** @return HasMany<StudentCommunication, $this> */
    public function studentCommunications(): HasMany
    {
        return $this->hasMany(StudentCommunication::class);
    }

    public function excludedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_exclusions')
            ->withTimestamps();
    }

    public function scopeOverlapping(Builder $query, CarbonInterface $startsAt, CarbonInterface $endsAt): Builder
    {
        return $query->where(function (Builder $query) use ($startsAt, $endsAt): void {
            $query
                ->where(function (Builder $query) use ($startsAt, $endsAt): void {
                    $query
                        ->whereNotNull('start_time')
                        ->whereNotNull('end_time')
                        ->where('start_time', '<', $endsAt)
                        ->where('end_time', '>', $startsAt);
                })
                ->orWhere(function (Builder $query) use ($startsAt, $endsAt): void {
                    $query
                        ->whereNotNull('start_time')
                        ->whereNull('end_time')
                        ->where('start_time', '>=', $startsAt)
                        ->where('start_time', '<', $endsAt);
                });
        });
    }

    public function scopeNotPassed(Builder $query, ?CarbonInterface $dateTime = null): void
    {
        self::applyNotPassedConstraint($query, $dateTime);
    }

    public function scopePassed(Builder $query, ?CarbonInterface $dateTime = null): void
    {
        self::applyPassedConstraint($query, $dateTime);
    }

    public function scopeNeedsSubstituteAttention(Builder $query, ?CarbonInterface $dateTime = null): void
    {
        $query
            ->whereNull('cancelled_at')
            ->whereNotNull('substitute_needed_at')
            ->whereNull('substitute_teacher_id');

        self::applyNotPassedConstraint($query, $dateTime);
    }

    /** @param array<int, mixed> $statuses */
    public function scopeWithSubstituteCoverageStatuses(Builder $query, array $statuses): void
    {
        /** @var array<string, EventSubstituteCoverageStatus> $normalizedStatuses */
        $normalizedStatuses = [];

        foreach ($statuses as $status) {
            $status = match (true) {
                $status instanceof EventSubstituteCoverageStatus => $status,
                is_string($status) => EventSubstituteCoverageStatus::tryFrom($status),
                default => null,
            };

            if ($status instanceof EventSubstituteCoverageStatus) {
                $normalizedStatuses[$status->value] = $status;
            }
        }

        if ($normalizedStatuses === []) {
            return;
        }

        $query->where(function (Builder $query) use ($normalizedStatuses): void {
            foreach ($normalizedStatuses as $status) {
                $query->orWhere(fn (Builder $query): Builder => self::applySubstituteCoverageStatusConstraint($query, $status));
            }
        });
    }

    public function scopeVisibleOnCalendar(Builder $query, Calendar $calendar, User $user): Builder
    {
        if (! $user->hasAnyRole(['owner', 'super_admin'])) {
            $isStaff = $user->hasRole('teacher');

            $query->where(function (Builder $query) use ($isStaff, $user): void {
                $query
                    ->where('substitute_teacher_id', $user->id)
                    ->orWhereNull('course_id')
                    ->orWhereHas('course', function (Builder $query) use ($isStaff, $user): void {
                        $query
                            ->where('is_private', false)
                            ->orWhere(function (Builder $query) use ($isStaff, $user): void {
                                $query
                                    ->where('is_private', true)
                                    ->where(function (Builder $query) use ($isStaff, $user): void {
                                        $query
                                            ->whereHas('teachers', fn (Builder $query): Builder => $query->whereKey($user->id))
                                            ->orWhereHas(
                                                'recurringPrivateLesson',
                                                fn (Builder $query): Builder => $isStaff
                                                    ? $query
                                                    : $query->where('user_id', $user->id),
                                            );
                                    });
                            });
                    });
            });
        }

        if (! $calendar->isMyCalendar()) {
            $query->whereDoesntHave(
                'excludedUsers',
                fn (Builder $query): Builder => $query->whereKey($user->id)
            );

            return $this->scopeAppliedToCalendar($query, $calendar);
        }

        $studentIds = $user->students()->pluck('id');
        $userMorphClass = $user->getMorphClass();
        $studentMorphClass = (new Student())->getMorphClass();
        $visibleCalendarIds = Calendar::query()
            ->visibleTo($user)
            ->whereNotIn('slug', [Calendar::SLUG_MY, Calendar::SLUG_EAC])
            ->pluck('id');
        $visibleCourseCalendarTagIds = Calendar::query()
            ->visibleTo($user)
            ->whereNotIn('slug', [Calendar::SLUG_MY, Calendar::SLUG_EAC])
            ->pluck('slug')
            ->map(fn (string $slug): ?int => Tag::findFromString($slug, Course::CALENDAR_TAG_TYPE)?->id)
            ->filter()
            ->values();

        return $query
            ->where(function (Builder $query) use ($studentIds, $studentMorphClass, $user, $userMorphClass, $visibleCalendarIds, $visibleCourseCalendarTagIds): void {
                $query
                    ->where('substitute_teacher_id', $user->id)
                    ->orWhere(function (Builder $query) use ($studentIds, $studentMorphClass, $user, $userMorphClass, $visibleCalendarIds, $visibleCourseCalendarTagIds): void {
                        $query
                            ->whereDoesntHave(
                                'excludedUsers',
                                fn (Builder $query): Builder => $query->whereKey($user->id)
                            )
                            ->where(function (Builder $query) use ($studentIds, $studentMorphClass, $user, $userMorphClass, $visibleCalendarIds, $visibleCourseCalendarTagIds): void {
                                $query
                                    ->where(function (Builder $query) use ($visibleCalendarIds, $visibleCourseCalendarTagIds): void {
                                        $query
                                            ->whereIn('calendar_id', $visibleCalendarIds)
                                            ->where(function (Builder $query) use ($visibleCourseCalendarTagIds): void {
                                                $query
                                                    ->whereNull('course_id')
                                                    ->orWhereDoesntHave('course.tags', fn (Builder $query): Builder => $query->where('type', Course::CALENDAR_TAG_TYPE))
                                                    ->orWhereHas('course.tags', fn (Builder $query): Builder => $query
                                                        ->where('type', Course::CALENDAR_TAG_TYPE)
                                                        ->whereIn('tags.id', $visibleCourseCalendarTagIds));
                                            });
                                    })
                                    ->orWhereHas('course.tags', fn (Builder $query): Builder => $query
                                        ->where('type', Course::CALENDAR_TAG_TYPE)
                                        ->whereIn('tags.id', $visibleCourseCalendarTagIds))
                                    ->orWhereHas('course.teachers', fn (Builder $query): Builder => $query->whereKey($user->id))
                                    ->orWhereHas('course.students', fn (Builder $query): Builder => $query->whereIn('students.id', $studentIds))
                                    ->orWhereHas('attendees', function (Builder $query) use ($studentIds, $studentMorphClass, $user, $userMorphClass): void {
                                        $query
                                            ->where(function (Builder $query) use ($user, $userMorphClass): void {
                                                $query
                                                    ->where('attendee_type', $userMorphClass)
                                                    ->where('attendee_id', $user->id);
                                            })
                                            ->orWhere(function (Builder $query) use ($studentIds, $studentMorphClass): void {
                                                $query
                                                    ->where('attendee_type', $studentMorphClass)
                                                    ->whereIn('attendee_id', $studentIds);
                                            });
                                    });
                            });
                    });
            });
    }

    public function scopeAppliedToCalendar(Builder $query, Calendar $calendar): Builder
    {
        $courseCalendarTagId = Tag::findFromString($calendar->slug, Course::CALENDAR_TAG_TYPE)?->id;

        return $query->where(function (Builder $query) use ($calendar, $courseCalendarTagId): void {
            $query
                ->where(function (Builder $query) use ($calendar): void {
                    $query
                        ->whereNull('course_id')
                        ->where('calendar_id', $calendar->id);
                })
                ->orWhere(function (Builder $query) use ($calendar, $courseCalendarTagId): void {
                    $query
                        ->whereNotNull('course_id')
                        ->where(function (Builder $query) use ($calendar, $courseCalendarTagId): void {
                            if ($courseCalendarTagId !== null) {
                                $query
                                    ->whereHas('course.tags', fn (Builder $query): Builder => $query->whereKey($courseCalendarTagId))
                                    ->orWhere(function (Builder $query) use ($calendar): void {
                                        $query
                                            ->whereDoesntHave('course.tags', fn (Builder $query): Builder => $query->where('type', Course::CALENDAR_TAG_TYPE))
                                            ->where('calendar_id', $calendar->id);
                                    });

                                return;
                            }

                            $query
                                ->whereDoesntHave('course.tags', fn (Builder $query): Builder => $query->where('type', Course::CALENDAR_TAG_TYPE))
                                ->where('calendar_id', $calendar->id);
                        });
                });
        });
    }

    public function isAccessibleToAdminUser(User $user): bool
    {
        if (! $user->hasCourseRestrictedAdminAccess()) {
            return true;
        }

        return $this->course()
            ->whereHas(
                'teachers',
                fn (Builder $query): Builder => $query->whereKey($user->id),
            )
            ->exists();
    }

    public function isViewableByAdminUser(User $user): bool
    {
        return $this->substitute_teacher_id === $user->id
            || $this->isAccessibleToAdminUser($user);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk(MediaDisks::private());

        $this->addMediaCollection('documents')
            ->useDisk(MediaDisks::private());
    }

    private static function applySubstituteCoverageStatusConstraint(
        Builder $query,
        EventSubstituteCoverageStatus $status,
    ): Builder {
        $hasPendingRequest = fn (Builder $query): Builder => $query
            ->where('status', EventSubstituteRequestStatus::Pending);
        $hasReleaseRequest = fn (Builder $query): Builder => $query
            ->where('status', EventSubstituteRequestStatus::Accepted)
            ->whereNotNull('release_requested_at')
            ->whereColumn('event_substitute_requests.teacher_id', 'events.substitute_teacher_id');

        return match ($status) {
            EventSubstituteCoverageStatus::NotNeeded => $query
                ->whereNull('substitute_teacher_id')
                ->whereNull('substitute_needed_at')
                ->whereDoesntHave('substituteRequests', $hasPendingRequest),
            EventSubstituteCoverageStatus::NeedsSubstitute => $query
                ->whereNull('substitute_teacher_id')
                ->whereNotNull('substitute_needed_at')
                ->whereDoesntHave('substituteRequests', $hasPendingRequest),
            EventSubstituteCoverageStatus::AwaitingResponse => $query
                ->whereNull('substitute_teacher_id')
                ->whereHas('substituteRequests', $hasPendingRequest),
            EventSubstituteCoverageStatus::Confirmed => $query
                ->whereNotNull('substitute_teacher_id')
                ->whereDoesntHave('substituteRequests', $hasReleaseRequest)
                ->whereDoesntHave('substituteRequests', $hasPendingRequest),
            EventSubstituteCoverageStatus::ReplacementPending => $query
                ->whereNotNull('substitute_teacher_id')
                ->whereDoesntHave('substituteRequests', $hasReleaseRequest)
                ->whereHas('substituteRequests', $hasPendingRequest),
            EventSubstituteCoverageStatus::ReleaseRequested => $query
                ->whereNotNull('substitute_teacher_id')
                ->whereHas('substituteRequests', $hasReleaseRequest),
        };
    }

    // public function registerMediaConversions(?Media $media = null): void
    // {
    //     $this->addMediaConversion('thumb')
    //         ->width(300)
    //         ->height(300)
    //         ->sharpen(10)
    //         ->performOnCollections('images')
    //         ->nonQueued();
    // }
}
