<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\HasCapacity;
use App\Contracts\Productable;
use App\Contracts\ProvidesStorefrontDetails;
use App\Support\MediaDisks;
use Illuminate\Database\Eloquent\Builder;
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

final class Course extends Model implements HasCapacity, HasMedia, Productable, ProvidesStorefrontDetails
{
    /** @use HasFactory<\Database\Factories\CourseFactory> */
    use HasFactory, InteractsWithMedia;

    protected $casts = [
        'id' => 'integer',
        'start_time' => 'datetime',
        'capacity' => 'integer',
        'duration' => 'integer',
        'teacher_id' => 'integer',
    ];

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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function courseForms(): HasMany
    {
        return $this->hasMany(CourseForm::class);
    }

    public function forms(): BelongsToMany
    {
        return $this->belongsToMany(Form::class, 'course_forms');
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
        $teacherName = $this->guest_teacher ?: $this->teacher?->fullName;

        return array_filter([
            'Start Time' => $this->start_time?->format('M j, Y g:i A'),
            'Duration' => "{$this->duration} minutes",
            'Teacher' => $teacherName,
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk(MediaDisks::public());

        $this->addMediaCollection('documents')
            ->useDisk(MediaDisks::public());

        $this->addMediaCollection('videos')
            ->useDisk(MediaDisks::public());
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
