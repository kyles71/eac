<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MediaDisks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
     * Scope to only include active products that are available for purchase.
     * For Course products, this also checks that there is remaining capacity.
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_active', true)
            ->where('price', '>', 0);
    }

    /**
     * Get the formatted price in dollars.
     */
    public function formattedPrice(): string
    {
        return format_money($this->price);
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
