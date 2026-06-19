<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\HasCapacity;
use App\Contracts\Productable;
use App\Contracts\ProvidesStorefrontDetails;
use App\Enums\CourseSemester;
use App\Support\MediaDisks;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Tags\HasTags;

final class Course extends Model implements HasCapacity, HasMedia, Productable, ProvidesStorefrontDetails
{
    /** @use HasFactory<\Database\Factories\CourseFactory> */
    use HasFactory, HasTags, InteractsWithMedia;

    public const string CALENDAR_TAG_TYPE = 'course-calendar';

    public const string GENERAL_TAG_TYPE = 'course-general';

    protected $casts = [
        'id' => 'integer',
        'semester' => CourseSemester::class,
        'start_time' => 'datetime',
        'capacity' => 'integer',
        'duration' => 'integer',
    ];

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function activeEvents(): HasMany
    {
        return $this->events()->where('start_time', '<', now())->where('end_time', '>', now());
    }

    public function nextEvents(): HasMany
    {
        return $this->events()->where('start_time', '>', now());
    }

    public function previousEvents(): HasMany
    {
        return $this->events()->where('end_time', '<', now());
    }

    public function nextEvent(): HasOne
    {
        return $this->events()->one()->ofMany([
            'start_time' => 'min',
            'id' => 'min',
        ], function (Builder $query): void {
            $query->where('start_time', '>', now());
        });
    }

    public function previousEvent(): HasOne
    {
        return $this->events()->one()->ofMany([
            'end_time' => 'max',
            'id' => 'max',
        ], function (Builder $query): void {
            $query->where('end_time', '<', now());
        });
    }

    public function teacherDisplayName(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => filled($this->guest_teacher)
                ? $this->guest_teacher
                : $this->formattedTeacherNames()
        );
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_teacher', 'course_id', 'teacher_id')
            ->withTimestamps()
            ->orderBy('first_name')
            ->orderBy('last_name');
    }

    public function courseForms(): HasMany
    {
        return $this->hasMany(CourseForm::class);
    }

    public function forms(): BelongsToMany
    {
        return $this->belongsToMany(Form::class, 'course_forms')
            ->using(CourseForm::class)
            ->withTimestamps();
    }

    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'productable');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function purchasers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'enrollments');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'enrollments');
    }

    /**
     * Get the number of available enrollment spots remaining.
     */
    public function getAvailableCapacity(): int
    {
        return $this->capacity - $this->enrollments()->count();
    }

    public function fulfillOrderItem(OrderItem $orderItem, User $purchaser): bool
    {
        for ($i = 0; $i < $orderItem->quantity; $i++) {
            Enrollment::query()->create([
                'course_id' => $this->id,
                'user_id' => $purchaser->id,
                'order_item_id' => $orderItem->id,
                'student_id' => null,
            ]);
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public function storefrontDetails(): array
    {
        $availableCapacity = $this->getAvailableCapacity();

        return array_filter([
            'Semester' => $this->semester?->getLabel(),
            'Start Time' => $this->start_time?->format('M j, Y g:i A'),
            'Duration' => "{$this->duration} minutes",
            'Teacher' => $this->teacherDisplayName,
            'Available Spots' => $availableCapacity > 0 ? (string) $availableCapacity : 'Sold Out',
        ], fn (?string $value): bool => filled($value));
    }

    /**
     * Scope to only include courses with available capacity.
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where(
            'capacity',
            '>',
            Enrollment::selectRaw('count(*)')
                ->whereColumn('enrollments.course_id', 'courses.id')
        );
    }

    public function scopeConcluded(Builder $query, ?Carbon $date = null): void
    {
        $date ??= Carbon::now();

        $query->where(function (Builder $query) use ($date): void {
            $query
                ->where(function (Builder $query) use ($date): void {
                    $query
                        ->whereHas('events')
                        ->whereDoesntHave(
                            'events',
                            fn (Builder $query): Builder => self::applyEventNotPassedConstraint($query, $date)
                        );
                })
                ->orWhere(function (Builder $query) use ($date): void {
                    $query
                        ->whereDoesntHave('events')
                        ->where('start_time', '<', $date);
                });
        });
    }

    public function scopeNotConcluded(Builder $query, ?Carbon $date = null): void
    {
        $date ??= Carbon::now();

        $query->where(function (Builder $query) use ($date): void {
            $query
                ->whereHas(
                    'events',
                    fn (Builder $query): Builder => self::applyEventNotPassedConstraint($query, $date)
                )
                ->orWhere(function (Builder $query) use ($date): void {
                    $query
                        ->whereDoesntHave('events')
                        ->where(function (Builder $query) use ($date): void {
                            $query
                                ->whereNull('start_time')
                                ->orWhere('start_time', '>=', $date);
                        });
                });
        });
    }

    public function hasConcluded(?Carbon $date = null): bool
    {
        $date ??= Carbon::now();

        if ($this->relationLoaded('events')) {
            if ($this->events->isNotEmpty()) {
                return ! $this->events->contains(
                    fn (Event $event): bool => self::eventHasNotPassed($event, $date)
                );
            }

            return $this->start_time?->lt($date) ?? false;
        }

        return ! self::query()
            ->whereKey($this->getKey())
            ->notConcluded($date)
            ->exists();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk(MediaDisks::public());

        $this->addMediaCollection('documents')
            ->useDisk(MediaDisks::private());

        $this->addMediaCollection('videos')
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

    protected static function booted(): void
    {
        self::created(function (Course $course): void {
            $course->loadMissing('tags');

            if ($course->tagsWithType(self::CALENDAR_TAG_TYPE)->isEmpty()) {
                $course->attachTag(Calendar::SLUG_EAC, self::CALENDAR_TAG_TYPE);
            }
        });
    }

    private static function applyEventNotPassedConstraint(Builder $query, Carbon $date): Builder
    {
        return $query->where(function (Builder $query) use ($date): void {
            $query
                ->where('end_time', '>=', $date)
                ->orWhere(function (Builder $query) use ($date): void {
                    $query
                        ->whereNull('end_time')
                        ->where('start_time', '>=', $date);
                });
        });
    }

    private static function eventHasNotPassed(Event $event, Carbon $date): bool
    {
        if ($event->end_time !== null) {
            return $event->end_time->gte($date);
        }

        return $event->start_time?->gte($date) ?? false;
    }

    private function formattedTeacherNames(): ?string
    {
        $teachers = $this->relationLoaded('teachers')
            ? $this->teachers
            : $this->teachers()->get();

        $teacherNames = $teachers
            ->pluck('fullName')
            ->filter()
            ->join(', ');

        return filled($teacherNames) ? $teacherNames : null;
    }
}
