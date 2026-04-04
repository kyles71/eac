<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Productable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class Costume extends Model implements Productable, HasMedia
{
    /** @use HasFactory<\Database\Factories\CostumeFactory> */
    use HasFactory;
    use InteractsWithMedia;

    protected $casts = [
        'id' => 'integer',
    ];

    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'productable');
    }

    public function fulfillOrderItem(OrderItem $orderItem, User $purchaser): bool
    {
        return false;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images');
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
