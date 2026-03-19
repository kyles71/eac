<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Store\FulfillGiftCard;
use App\Contracts\Productable;
use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

final class GiftCardType extends Model implements Productable
{
    /** @use HasFactory<\Database\Factories\GiftCardTypeFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'denomination' => 'integer',
        'restricted_to_product_type' => ProductType::class,
    ];

    public function product(): MorphOne
    {
        return $this->morphOne(Product::class, 'productable');
    }

    public function fulfillOrderItem(OrderItem $orderItem, User $purchaser): bool
    {
        $fulfillGiftCard = new FulfillGiftCard;
        $fulfillGiftCard->handle($orderItem, $purchaser);

        return true;
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function giftCards(): HasMany
    {
        return $this->hasMany(GiftCard::class);
    }

    public function restrictedCredits(): HasMany
    {
        return $this->hasMany(RestrictedCredit::class);
    }

    /**
     * Whether this gift card type has any product or product-type restrictions.
     */
    public function hasRestrictions(): bool
    {
        return $this->restricted_to_product_type !== null || $this->products()->exists();
    }

    /**
     * Check if this gift card type applies to a given product.
     * If the type has no restrictions, it applies to all products.
     */
    public function appliesToProduct(Product $product): bool
    {
        if (! $this->hasRestrictions()) {
            return true;
        }

        // Check product-type restriction
        if ($this->restricted_to_product_type !== null) {
            $productType = ProductType::fromProductableType($product->productable_type);

            if ($productType !== $this->restricted_to_product_type) {
                return false;
            }
        }

        // Check specific product restrictions
        if ($this->products()->exists()) {
            return $this->products()->where('products.id', $product->id)->exists();
        }

        return true;
    }

    /**
     * Get the formatted denomination in dollars, or "Custom" if 0.
     */
    public function formattedDenomination(): string
    {
        if ($this->denomination === 0) {
            return 'Custom';
        }

        return '$'.number_format($this->denomination / 100, 2);
    }

    /**
     * Get a human-readable description of this type's restrictions.
     */
    public function restrictionSummary(): string
    {
        if (! $this->hasRestrictions()) {
            return 'Unrestricted';
        }

        $parts = [];

        if ($this->restricted_to_product_type !== null) {
            $parts[] = $this->restricted_to_product_type->getLabel().' products only';
        }

        $productCount = $this->products()->count();
        if ($productCount > 0) {
            $parts[] = $productCount.' '.($productCount === 1 ? 'product' : 'products');
        }

        return implode(', ', $parts);
    }
}
