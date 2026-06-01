<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\ProvidesStorefrontDetails;
use App\Support\MediaDisks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class Product extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory, InteractsWithMedia;

    protected $casts = [
        'id' => 'integer',
        'price' => 'integer',
        'is_active' => 'boolean',
        'include_productable_images' => 'boolean',
        'requires_course_id' => 'integer',
    ];

    public function productable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requiresCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'requires_course_id');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Scope to only include active products with a valid price.
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_active', true)
            ->where('price', '>', 0);
    }

    public function scopePurchasableBy(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->whereNull('requires_course_id')
                ->orWhereIn('requires_course_id', $user->enrollments()->select('course_id'));
        });
    }

    /**
     * Get the formatted price in dollars.
     */
    public function formattedPrice(): string
    {
        return format_money($this->price);
    }

    /**
     * @return Collection<int, Media>
     */
    public function galleryImages(): Collection
    {
        $images = $this->getMedia('images');

        if (! $this->include_productable_images || ! ($this->productable instanceof HasMedia)) {
            return $images;
        }

        return $images->merge($this->productable->getMedia('images'))->values();
    }

    /**
     * @return array<string, string>
     */
    public function storefrontDetails(): array
    {
        if (! $this->productable instanceof ProvidesStorefrontDetails) {
            return [];
        }

        return $this->productable->storefrontDetails();
    }

    public function canBePurchasedBy(User $user): bool
    {
        if ($this->requires_course_id === null) {
            return true;
        }

        return $user->enrollments()
            ->where('course_id', $this->requires_course_id)
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
}
