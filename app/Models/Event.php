<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MediaDisks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Tags\Tag;

final class Event extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'course_id' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }

    public function excludedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_exclusions')
            ->withTimestamps();
    }

    public function scopeOverlapping(Builder $query, Carbon $startsAt, Carbon $endsAt): Builder
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk(MediaDisks::public());

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
