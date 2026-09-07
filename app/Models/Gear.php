<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Productable;
use App\Contracts\ProvidesStorefrontDetails;
use App\Support\MediaDisks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Product> $products
 */
final class Gear extends Model implements HasMedia, Productable, ProvidesStorefrontDetails
{
    /** @use HasFactory<\Database\Factories\GearFactory> */
    use HasFactory;

    use InteractsWithMedia;

    protected $table = 'gear';

    protected $casts = [
        'id' => 'integer',
    ];

    /** @return MorphMany<Product, $this> */
    public function products(): MorphMany
    {
        return $this->morphMany(Product::class, 'productable');
    }

    public function fulfillOrderItem(OrderItem $orderItem, User $purchaser): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public function storefrontDetails(): array
    {
        return [];
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

    // public function registerMediaConversions(?Media $media = null): void
    // {
    //     $this->addMediaConversion('thumb')
    //         ->width(300)
    //         ->height(300)
    //         ->sharpen(10)
    //         ->nonQueued();
    // }
}
