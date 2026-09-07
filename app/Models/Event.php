<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventSubstituteCoverageStatus;
use App\Enums\EventSubstituteRequestStatus;
use App\Enums\EventTeacherAssignmentMode;
use App\Support\MediaDisks;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
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
        'teacher_assignment_mode' => EventTeacherAssignmentMode::class,
        'teacher_rotation_sequence' => 'integer',
        'cancelled_at' => 'datetime',
        'cancelled_by_user_id' => 'integer',
        'reminder_processed_at' => 'datetime',
    ];

    private bool $legacySubstituteTeacherWasSet = false;

    private ?int $legacySubstituteTeacherId = null;

    private bool $legacySubstituteNeededWasSet = false;

    private mixed $legacySubstituteNeededAt = null;

    public static function applyAdminAccessConstraint(Builder $query, User $user): Builder
    {
        if (! $user->hasCourseRestrictedAdminAccess()) {
            return $query;
        }

        return $query->whereHas(
            'teacherAssignments',
            fn (Builder $query): Builder => $query->where('teacher_id', $user->id),
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
                ->whereHas(
                    'substituteCoverages',
                    fn (Builder $query): Builder => $query->where('substitute_teacher_id', $user->id),
                )
                ->orWhereHas(
                    'teacherAssignments',
                    fn (Builder $query): Builder => $query->where('teacher_id', $user->id),
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

    /** @param array<int, mixed> $statuses */
    public static function applySubstituteCoverageStatusesConstraint(Builder $query, array $statuses): Builder
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
            return $query;
        }

        return $query->where(function (Builder $query) use ($normalizedStatuses): void {
            foreach ($normalizedStatuses as $status) {
                $query->orWhere(fn (Builder $query): Builder => self::applySubstituteCoverageStatusConstraint($query, $status));
            }
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

    /** @return HasMany<EventTeacherAssignment, $this> */
    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(EventTeacherAssignment::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'event_teacher_assignments',
            'event_id',
            'teacher_id',
        )
            ->withTimestamps()
            ->orderBy('first_name')
            ->orderBy('last_name');
    }

    /** @return HasMany<EventSubstituteCoverage, $this> */
    public function substituteCoverages(): HasMany
    {
        return $this->hasMany(EventSubstituteCoverage::class);
    }

    /** @return HasMany<EventSubstituteCoverage, $this> */
    public function activeSubstituteCoverages(): HasMany
    {
        return $this->substituteCoverages()->active();
    }

    /** @return BelongsToMany<User, $this> */
    public function substituteTeachers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'event_substitute_coverages',
            'event_id',
            'substitute_teacher_id',
        )
            ->wherePivotNotNull('substitute_teacher_id')
            ->withPivot(['covered_teacher_id', 'needed_at', 'closed_at'])
            ->orderBy('first_name')
            ->orderBy('last_name');
    }

    /** @return HasMany<EventSubstituteRequest, $this> */
    public function substituteRequests(): HasMany
    {
        return $this->hasMany(EventSubstituteRequest::class);
    }

    public function pendingSubstituteRequest(): ?EventSubstituteRequest
    {
        return $this->substituteRequests()
            ->whereHas('coverage', fn (Builder $query): Builder => $query
                ->whereNotNull('needed_at')
                ->whereNull('closed_at'))
            ->where('status', EventSubstituteRequestStatus::Pending)
            ->latest('id')
            ->first();
    }

    public function currentSubstituteRequest(): ?EventSubstituteRequest
    {
        return $this->substituteCoverages()
            ->whereNotNull('substitute_teacher_id')
            ->with('requests')
            ->get()
            ->map(fn (EventSubstituteCoverage $coverage): ?EventSubstituteRequest => $coverage->currentSubstituteRequest())
            ->filter()
            ->sortByDesc('id')
            ->first();
    }

    /** @return Collection<int, EventSubstituteCoverage> */
    public function currentSubstituteCoverages(): Collection
    {
        return $this->activeSubstituteCoverages()
            ->with(['coveredTeacher', 'substituteTeacher', 'requests.teacher'])
            ->orderBy('id')
            ->get();
    }

    public function coverageForTeacher(User|int $teacher): ?EventSubstituteCoverage
    {
        $teacherId = $teacher instanceof User ? $teacher->id : $teacher;

        return $this->activeSubstituteCoverages()
            ->where('covered_teacher_id', $teacherId)
            ->latest('id')
            ->first();
    }

    public function isAssignedTeacher(User $teacher): bool
    {
        return $this->teacherAssignments()
            ->where('teacher_id', $teacher->id)
            ->exists();
    }

    public function isConfirmedSubstitute(User $teacher): bool
    {
        return $this->substituteCoverages()
            ->where('substitute_teacher_id', $teacher->id)
            ->exists();
    }

    public function substituteCoverageStatus(): EventSubstituteCoverageStatus
    {
        $coverages = $this->currentSubstituteCoverages();

        if ($coverages->isEmpty()) {
            return EventSubstituteCoverageStatus::NotNeeded;
        }

        if ($coverages->contains(
            fn (EventSubstituteCoverage $coverage): bool => $coverage->currentSubstituteRequest()?->hasReleaseRequest() === true,
        )) {
            return EventSubstituteCoverageStatus::ReleaseRequested;
        }

        if ($coverages->contains(
            fn (EventSubstituteCoverage $coverage): bool => $coverage->substitute_teacher_id === null
                && ! $coverage->pendingRequest() instanceof EventSubstituteRequest,
        )) {
            return EventSubstituteCoverageStatus::NeedsSubstitute;
        }

        if ($coverages->contains(
            fn (EventSubstituteCoverage $coverage): bool => $coverage->substitute_teacher_id === null
                && $coverage->pendingRequest() instanceof EventSubstituteRequest,
        )) {
            return EventSubstituteCoverageStatus::AwaitingResponse;
        }

        if ($coverages->contains(
            fn (EventSubstituteCoverage $coverage): bool => $coverage->substitute_teacher_id !== null
                && $coverage->pendingRequest() instanceof EventSubstituteRequest,
        )) {
            return EventSubstituteCoverageStatus::ReplacementPending;
        }

        return EventSubstituteCoverageStatus::Confirmed;
    }

    public function substituteCoverageLabel(): string
    {
        $coverages = $this->currentSubstituteCoverages();
        $status = $this->substituteCoverageStatus();

        if ($coverages->count() < 2) {
            return $status->getLabel();
        }

        return $status->getLabel().' ('.$coverages->whereNotNull('substitute_teacher_id')->count().'/'.$coverages->count().' covered)';
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

    /** @return MorphMany<OrderItemFulfillment, $this> */
    public function orderItemFulfillments(): MorphMany
    {
        return $this->morphMany(OrderItemFulfillment::class, 'source');
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

    public function scopeRegularlyAssignedToTeacher(Builder $query, User|int $teacher): Builder
    {
        $teacherId = $teacher instanceof User ? $teacher->id : $teacher;

        return $query->whereHas(
            'teacherAssignments',
            fn (Builder $query): Builder => $query->where('teacher_id', $teacherId),
        );
    }

    public function scopeAssignedToTeacher(Builder $query, User|int $teacher): Builder
    {
        $teacherId = $teacher instanceof User ? $teacher->id : $teacher;

        return $query->where(function (Builder $query) use ($teacherId): void {
            $query
                ->whereHas(
                    'teacherAssignments',
                    fn (Builder $query): Builder => $query->where('teacher_id', $teacherId),
                )
                ->orWhereHas(
                    'substituteCoverages',
                    fn (Builder $query): Builder => $query->where('substitute_teacher_id', $teacherId),
                );
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
            ->whereHas(
                'activeSubstituteCoverages',
                fn (Builder $query): Builder => $query->whereNull('substitute_teacher_id'),
            );

        self::applyNotPassedConstraint($query, $dateTime);
    }

    /** @param array<int, mixed> $statuses */
    public function scopeWithSubstituteCoverageStatuses(Builder $query, array $statuses): void
    {
        self::applySubstituteCoverageStatusesConstraint($query, $statuses);
    }

    public function scopeVisibleOnCalendar(Builder $query, Calendar $calendar, User $user): Builder
    {
        if (! $user->hasAnyRole(['owner', 'super_admin'])) {
            $query->where(function (Builder $query) use ($user): void {
                $query
                    ->whereHas(
                        'substituteCoverages',
                        fn (Builder $query): Builder => $query->where('substitute_teacher_id', $user->id),
                    )
                    ->orWhereHas(
                        'teacherAssignments',
                        fn (Builder $query): Builder => $query->where('teacher_id', $user->id),
                    )
                    ->orWhereNull('course_id')
                    ->orWhereHas('course', function (Builder $query) use ($user): void {
                        $query
                            ->where('is_private', false)
                            ->orWhere(function (Builder $query) use ($user): void {
                                $query
                                    ->where('is_private', true)
                                    ->whereHas(
                                        'recurringPrivateLesson',
                                        fn (Builder $query): Builder => $query->where('user_id', $user->id),
                                    );
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
                    ->whereHas(
                        'substituteCoverages',
                        fn (Builder $query): Builder => $query->where('substitute_teacher_id', $user->id),
                    )
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
                                    ->orWhereHas(
                                        'teacherAssignments',
                                        fn (Builder $query): Builder => $query->where('teacher_id', $user->id),
                                    )
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

        return $this->isAssignedTeacher($user);
    }

    public function isViewableByAdminUser(User $user): bool
    {
        return $this->isConfirmedSubstitute($user)
            || $this->isAccessibleToAdminUser($user);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk(MediaDisks::private());

        $this->addMediaCollection('documents')
            ->useDisk(MediaDisks::private());
    }

    protected static function booted(): void
    {
        self::created(function (Event $event): void {
            $event->persistLegacySubstituteState();
        });
    }

    /** @return Attribute<int|null, int|null> */
    protected function substituteTeacherId(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => $this->substituteCoverages()
                ->whereNotNull('substitute_teacher_id')
                ->latest('id')
                ->value('substitute_teacher_id'),
            set: function (mixed $value): array {
                $this->legacySubstituteTeacherWasSet = true;
                $this->legacySubstituteTeacherId = is_numeric($value) ? (int) $value : null;

                if ($this->exists) {
                    $this->persistLegacySubstituteState();
                }

                return [];
            },
        );
    }

    /** @return Attribute<mixed, mixed> */
    protected function substituteNeededAt(): Attribute
    {
        return Attribute::make(
            get: fn (): mixed => $this->activeSubstituteCoverages()
                ->latest('id')
                ->value('needed_at'),
            set: function (mixed $value): array {
                $this->legacySubstituteNeededWasSet = true;
                $this->legacySubstituteNeededAt = $value;

                if ($this->exists) {
                    $this->persistLegacySubstituteState();
                }

                return [];
            },
        );
    }

    private static function applySubstituteCoverageStatusConstraint(
        Builder $query,
        EventSubstituteCoverageStatus $status,
    ): Builder {
        $hasPendingRequest = fn (Builder $query): Builder => $query->where('status', EventSubstituteRequestStatus::Pending);
        $coverageHasPendingRequest = fn (Builder $query): Builder => $query->whereHas('requests', $hasPendingRequest);
        $coverageHasReleaseRequest = fn (Builder $query): Builder => $query
            ->whereNotNull('substitute_teacher_id')
            ->whereHas('requests', fn (Builder $query): Builder => $query
                ->where('status', EventSubstituteRequestStatus::Accepted)
                ->whereNotNull('release_requested_at')
                ->whereColumn(
                    'event_substitute_requests.teacher_id',
                    'event_substitute_coverages.substitute_teacher_id',
                ));

        return match ($status) {
            EventSubstituteCoverageStatus::NotNeeded => $query
                ->whereDoesntHave('activeSubstituteCoverages'),
            EventSubstituteCoverageStatus::NeedsSubstitute => $query
                ->whereDoesntHave('activeSubstituteCoverages', $coverageHasReleaseRequest)
                ->whereHas('activeSubstituteCoverages', fn (Builder $query): Builder => $query
                    ->whereNull('substitute_teacher_id')
                    ->whereDoesntHave('requests', $hasPendingRequest)),
            EventSubstituteCoverageStatus::AwaitingResponse => $query
                ->whereDoesntHave('activeSubstituteCoverages', $coverageHasReleaseRequest)
                ->whereDoesntHave('activeSubstituteCoverages', fn (Builder $query): Builder => $query
                    ->whereNull('substitute_teacher_id')
                    ->whereDoesntHave('requests', $hasPendingRequest))
                ->whereHas('activeSubstituteCoverages', fn (Builder $query): Builder => $query
                    ->whereNull('substitute_teacher_id')
                    ->whereHas('requests', $hasPendingRequest)),
            EventSubstituteCoverageStatus::Confirmed => $query
                ->whereHas('activeSubstituteCoverages')
                ->whereDoesntHave('activeSubstituteCoverages', $coverageHasReleaseRequest)
                ->whereDoesntHave('activeSubstituteCoverages', fn (Builder $query): Builder => $query->whereNull('substitute_teacher_id'))
                ->whereDoesntHave('activeSubstituteCoverages', $coverageHasPendingRequest),
            EventSubstituteCoverageStatus::ReplacementPending => $query
                ->whereDoesntHave('activeSubstituteCoverages', $coverageHasReleaseRequest)
                ->whereDoesntHave('activeSubstituteCoverages', fn (Builder $query): Builder => $query->whereNull('substitute_teacher_id'))
                ->whereHas('activeSubstituteCoverages', fn (Builder $query): Builder => $query
                    ->whereNotNull('substitute_teacher_id')
                    ->whereHas('requests', $hasPendingRequest)),
            EventSubstituteCoverageStatus::ReleaseRequested => $query
                ->whereHas('activeSubstituteCoverages', $coverageHasReleaseRequest),
        };
    }

    private function persistLegacySubstituteState(): void
    {
        if (! $this->legacySubstituteTeacherWasSet && ! $this->legacySubstituteNeededWasSet) {
            return;
        }

        $coverage = $this->activeSubstituteCoverages()->latest('id')->first();
        $substituteTeacherId = $this->legacySubstituteTeacherWasSet
            ? $this->legacySubstituteTeacherId
            : $coverage?->substitute_teacher_id;
        $neededAt = $this->legacySubstituteNeededWasSet
            ? $this->legacySubstituteNeededAt
            : $coverage?->needed_at;

        if ($neededAt === null && $substituteTeacherId === null) {
            $coverage?->update([
                'closed_at' => now(),
                'closure_reason' => 'Closed through the legacy event substitute compatibility attribute.',
            ]);
            $this->resetLegacySubstituteState();

            return;
        }

        if (! $coverage instanceof EventSubstituteCoverage) {
            $assignedTeacherIds = $this->teacherAssignments()->pluck('teacher_id');
            $coverage = $this->substituteCoverages()->create([
                'covered_teacher_id' => $assignedTeacherIds->count() === 1 ? $assignedTeacherIds->first() : null,
                'needed_at' => $neededAt ?? now(),
            ]);
        }

        $coverage->update([
            'needed_at' => $neededAt ?? $coverage->needed_at ?? now(),
            'substitute_teacher_id' => $substituteTeacherId,
        ]);
        $this->resetLegacySubstituteState();
    }

    private function resetLegacySubstituteState(): void
    {
        $this->legacySubstituteTeacherWasSet = false;
        $this->legacySubstituteTeacherId = null;
        $this->legacySubstituteNeededWasSet = false;
        $this->legacySubstituteNeededAt = null;
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
