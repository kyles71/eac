<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Productable;
use App\Contracts\ProvidesStorefrontDetails;
use App\Support\MediaDisks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property-read Product|null $product
 */
final class Costume extends Model implements HasMedia, Productable, ProvidesStorefrontDetails
{
    /** @use HasFactory<\Database\Factories\CostumeFactory> */
    use HasFactory, InteractsWithMedia;

    protected $casts = [
        'id' => 'integer',
        'course_id' => 'integer',
    ];

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return MorphOne<Product, $this> */
    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'productable');
    }

    /** @return MorphMany<Product, $this> */
    public function products(): MorphMany
    {
        return $this->morphMany(Product::class, 'productable');
    }

    public function fulfillOrderItem(OrderItem $orderItem, User $purchaser): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public function storefrontDetails(): array
    {
        return ['Course' => $this->course->name];
    }

    public function canBeDeleted(): bool
    {
        return $this->products()->where(function (Builder $query): void {
            $query
                ->where('is_active', true)
                ->orWhereHas('orderItems');
        })->doesntExist();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useDisk(MediaDisks::public());
    }

    protected static function booted(): void
    {
        self::updating(function (Costume $costume): void {
            if (! $costume->isDirty('course_id')) {
                return;
            }

            $hasInvalidAssignment = $costume->products()
                ->whereHas('assignedStudents', fn (Builder $query): Builder => $query
                    ->whereDoesntHave('enrollments', fn (Builder $query): Builder => $query
                        ->where('course_id', $costume->course_id)))
                ->exists();

            if ($hasInvalidAssignment) {
                throw ValidationException::withMessages([
                    'course_id' => 'Remove student assignments that are not enrolled in the new course before changing the course.',
                ]);
            }
        });
    }
}
