<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\HasCapacity;
use App\Contracts\Productable;
use App\Contracts\ProvidesStorefrontDetails;
use App\Enums\CourseSemester;
use App\Support\MediaDisks;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Tags\HasTags;

/**
 * @property-read Product|null $product
 * @property-read CourseSemester|null $semester
 * @property-read string|null $teacherDisplayName
 * @property-read int $reportable_enrollments_count
 * @property-read int $reportable_holds_count
 */
final class Course extends Model implements HasCapacity, HasMedia, Productable, ProvidesStorefrontDetails
{
    /** @use HasFactory<\Database\Factories\CourseFactory> */
    use HasFactory, HasTags, InteractsWithMedia;

    public const string CALENDAR_TAG_TYPE = 'course-calendar';

    public const string GENERAL_TAG_TYPE = 'course-general';

    protected $casts = [
        'id' => 'integer',
        'academic_term_id' => 'integer',
        'capacity' => 'integer',
        'is_private' => 'boolean',
        'event_reminder_processed_at' => 'datetime',
    ];

    public static function applyActiveTeachingAccessConstraint(Builder $query, User $user): Builder
    {
        if (! $user->hasCourseRestrictedAdminAccess()) {
            return $query;
        }

        self::applyNotConcludedConstraint($query, Carbon::now());

        return $query->whereHas(
            'teachers',
            fn (Builder $query): Builder => $query->whereKey($user->id),
        );
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    public function semester(): Attribute
    {
        return Attribute::make(
            get: fn (): ?CourseSemester => $this->academicTerm?->semester,
        );
    }

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

    public function firstScheduledEvent(): ?Event
    {
        if ($this->relationLoaded('events')) {
            return $this->events
                ->filter(fn (Event $event): bool => $event->start_time instanceof CarbonInterface)
                ->sortBy('start_time')
                ->first();
        }

        return $this->events()
            ->whereNotNull('start_time')
            ->orderBy('start_time')
            ->orderBy('id')
            ->first();
    }

    public function nextScheduledEvent(?CarbonInterface $date = null): ?Event
    {
        $date ??= Carbon::now();

        if ($this->relationLoaded('events')) {
            return $this->events
                ->filter(fn (Event $event): bool => $event->start_time?->gte($date) ?? false)
                ->sortBy('start_time')
                ->first();
        }

        return $this->events()
            ->where('start_time', '>=', $date)
            ->orderBy('start_time')
            ->orderBy('id')
            ->first();
    }

    public function lastScheduledEvent(): ?Event
    {
        if ($this->relationLoaded('events')) {
            return $this->events
                ->filter(fn (Event $event): bool => $event->start_time instanceof CarbonInterface)
                ->sortByDesc(fn (Event $event): mixed => $event->end_time ?? $event->start_time)
                ->first();
        }

        return $this->events()
            ->whereNotNull('start_time')
            ->orderByRaw('COALESCE(end_time, start_time) desc')
            ->orderByDesc('id')
            ->first();
    }

    public function firstMeetingStartsAt(): ?CarbonInterface
    {
        return $this->firstScheduledEvent()?->start_time;
    }

    public function nextMeetingStartsAt(?CarbonInterface $date = null): ?CarbonInterface
    {
        return $this->nextScheduledEvent($date)?->start_time;
    }

    public function lastMeetingEndsAt(): ?CarbonInterface
    {
        $event = $this->lastScheduledEvent();

        if ($event === null) {
            return null;
        }

        return $event->end_time ?? $event->start_time;
    }

    public function scheduledDurationMinutes(?Event $event = null): ?int
    {
        $event ??= $this->firstScheduledEvent();

        if ($event?->start_time instanceof CarbonInterface && $event->end_time instanceof CarbonInterface) {
            return (int) round($event->start_time->diffInMinutes($event->end_time, true));
        }

        return null;
    }

    /** @return BelongsToMany<User, $this> */
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

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasOne<RecurringPrivateLesson, $this> */
    public function recurringPrivateLesson(): HasOne
    {
        return $this->hasOne(RecurringPrivateLesson::class);
    }

    /** @return HasMany<CourseHoldSeat, $this> */
    public function holdSeats(): HasMany
    {
        return $this->hasMany(CourseHoldSeat::class);
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
    public function getAvailableCapacity(?User $user = null): int
    {
        $publicCapacity = max(
            0,
            $this->capacity
                - $this->enrollments()->count()
                - CourseHoldSeat::query()
                    ->where('course_id', $this->id)
                    ->reservingCapacity()
                    ->count(),
        );

        if ($user === null) {
            return $publicCapacity;
        }

        $heldForUser = CourseHoldSeat::query()
            ->where('course_id', $this->id)
            ->whereHas('hold', fn (Builder $query): Builder => $query
                ->where('user_id', $user->id)
                ->where('expires_at', '>', now()))
            ->available()
            ->count();

        return $publicCapacity + $heldForUser;
    }

    public function fulfillOrderItem(OrderItem $orderItem, User $purchaser): bool
    {
        if ($orderItem->course_hold_id !== null) {
            $heldSeats = CourseHoldSeat::query()
                ->where('claimed_order_item_id', $orderItem->id)
                ->where('course_id', $this->id)
                ->whereDoesntHave('enrollment')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($heldSeats->count() !== $orderItem->quantity) {
                return false;
            }

            /** @var CourseHoldSeat $heldSeat */
            foreach ($heldSeats as $heldSeat) {
                Enrollment::query()->create([
                    'course_id' => $this->id,
                    'user_id' => $purchaser->id,
                    'order_item_id' => $orderItem->id,
                    'course_hold_seat_id' => $heldSeat->id,
                    'student_id' => $heldSeat->student_id,
                ]);
            }

            return true;
        }

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
        $duration = $this->scheduledDurationMinutes();

        return array_filter([
            'Semester' => $this->academicTerm?->display_name,
            'Start Time' => $this->formattedStorefrontStartTime(),
            'Duration' => $duration !== null ? "{$duration} minutes" : null,
            'Teacher' => $this->teacherDisplayName,
            'Available Spots' => $availableCapacity > 0 ? (string) $availableCapacity : 'Sold Out',
        ], fn (?string $value): bool => filled($value));
    }

    /**
     * Scope to only include courses with available capacity.
     */
    public function scopeAvailable(Builder $query): void
    {
        $enrollmentCount = Enrollment::query()
            ->selectRaw('count(*)')
            ->whereColumn('enrollments.course_id', 'courses.id');
        $holdCount = CourseHoldSeat::query()
            ->selectRaw('count(*)')
            ->whereColumn('course_hold_seats.course_id', 'courses.id')
            ->reservingCapacity();

        $query->whereRaw(
            'courses.capacity > (('.$enrollmentCount->toSql().') + ('.$holdCount->toSql().'))',
            [...$enrollmentCount->getBindings(), ...$holdCount->getBindings()],
        );
    }

    public function scopeConcluded(Builder $query, ?Carbon $date = null): void
    {
        $date ??= Carbon::now();

        $query->where(function (Builder $query) use ($date): void {
            $query
                ->whereDoesntHave('events')
                ->orWhere(function (Builder $query) use ($date): void {
                    $query
                        ->whereHas('events')
                        ->whereDoesntHave(
                            'events',
                            fn (Builder $query): Builder => self::applyEventNotPassedConstraint($query, $date)
                        );
                });
        });
    }

    public function scopeNotConcluded(Builder $query, ?Carbon $date = null): void
    {
        $date ??= Carbon::now();

        self::applyNotConcludedConstraint($query, $date);
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

            return true;
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

    private static function applyNotConcludedConstraint(Builder $query, Carbon $date): Builder
    {
        return $query->whereHas(
            'events',
            fn (Builder $query): Builder => self::applyEventNotPassedConstraint($query, $date)
        );
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

    private function formattedStorefrontStartTime(): ?string
    {
        return $this->firstMeetingStartsAt()
            ?->copy()
            ->timezone((string) config('app.display_timezone', config('app.timezone')))
            ->format('M j, Y g:i A');
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
