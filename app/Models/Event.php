<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MediaDisks;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function scopeVisibleOnCalendar(Builder $query, Calendar $calendar, User $user): Builder
    {
        $query->whereDoesntHave(
            'excludedUsers',
            fn (Builder $query): Builder => $query->whereKey($user->id)
        );

        if (! $calendar->isMyCalendar()) {
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk(MediaDisks::private());

        $this->addMediaCollection('documents')
            ->useDisk(MediaDisks::private());
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
