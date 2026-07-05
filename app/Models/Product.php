<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\ProvidesStorefrontDetails;
use App\Contracts\RequiresAddToCartInformation;
use App\Enums\ProductAvailabilityStatus;
use App\Services\ProductAvailabilityService;
use App\Support\MediaDisks;
use Carbon\CarbonInterface;
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
        'send_purchase_notification' => 'boolean',
        'requires_course_id' => 'integer',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
    ];

    public function productable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requiresCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'requires_course_id');
    }

    /** @return HasMany<ProductEarlyAccessWindow, $this> */
    public function earlyAccessWindows(): HasMany
    {
        return $this->hasMany(ProductEarlyAccessWindow::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<ProductQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(ProductQuestion::class)->orderBy('sort_order');
    }

    /**
     * Scope to only include active products with a valid price and open schedule.
     */
    public function scopeAvailable(Builder $query, ?CarbonInterface $at = null): void
    {
        app(ProductAvailabilityService::class)->applyNormallyAvailableToQuery($query, $at);
    }

    public function scopeVisibleTo(Builder $query, User $user, ?CarbonInterface $at = null): void
    {
        app(ProductAvailabilityService::class)->applyVisibleToQuery($query, $user, $at);
    }

    public function scopePurchasableBy(Builder $query, User $user, ?CarbonInterface $at = null): void
    {
        $this->scopeVisibleTo($query, $user, $at);
    }

    /**
     * Get the formatted price in dollars.
     */
    public function formattedPrice(): string
    {
        return format_money($this->price ?? 0);
    }

    public function allowsCustomGiftCardAmount(): bool
    {
        return $this->usesCustomerEnteredPricing();
    }

    public function requiresAddToCartInformation(): bool
    {
        $this->loadMissing('productable');

        return $this->productable instanceof RequiresAddToCartInformation
            && $this->productable->requiresAddToCartInformation();
    }

    public function usesCustomerEnteredPricing(): bool
    {
        $this->loadMissing('productable');

        return $this->productable instanceof GiftCardType
            && $this->productable->allows_custom_amount;
    }

    public function requiresFixedPrice(): bool
    {
        return ! $this->usesCustomerEnteredPricing();
    }

    public function hasValidPricing(): bool
    {
        if ($this->usesCustomerEnteredPricing()) {
            /** @var GiftCardType $giftCardType */
            $giftCardType = $this->productable;

            return $giftCardType->hasValidCustomAmountConfiguration();
        }

        return ($this->price ?? 0) > 0;
    }

    public function minimumCustomGiftCardAmount(): ?int
    {
        $this->loadMissing('productable');

        if (! $this->productable instanceof GiftCardType) {
            return null;
        }

        return $this->productable->minimumCustomAmount();
    }

    public function suggestedCustomGiftCardAmount(): ?int
    {
        $this->loadMissing('productable');

        if (! $this->productable instanceof GiftCardType) {
            return null;
        }

        return $this->productable->suggestedCustomAmount();
    }

    public function storefrontPriceLabel(): string
    {
        if (! $this->allowsCustomGiftCardAmount()) {
            return $this->formattedPrice();
        }

        /** @var GiftCardType $giftCardType */
        $giftCardType = $this->productable;

        return 'Name your price from '.$giftCardType->formattedMinimumCustomAmount();
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

    public function availabilityFor(?User $user = null, ?CarbonInterface $at = null): ProductAvailabilityStatus
    {
        return app(ProductAvailabilityService::class)->resultFor($this, $user, $at);
    }

    public function availabilityStatus(?CarbonInterface $at = null): ProductAvailabilityStatus
    {
        return app(ProductAvailabilityService::class)->adminStatusFor($this, $at);
    }

    public function canBePurchasedBy(User $user, ?CarbonInterface $at = null): bool
    {
        return app(ProductAvailabilityService::class)
            ->resultFor($this, $user, $at)
            ->isPurchasable();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->is_active && $this->orderItems()->doesntExist();
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
