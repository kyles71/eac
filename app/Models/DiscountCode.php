<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DiscountCode extends Model
{
    /** @use HasFactory<\Database\Factories\DiscountCodeFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'type' => DiscountType::class,
        'value' => 'integer',
        'min_order_amount' => 'integer',
        'max_uses' => 'integer',
        'times_used' => 'integer',
        'max_uses_per_user' => 'integer',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Scope to only include active, non-expired discount codes.
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function (Builder $q): void {
                $q->whereNull('max_uses')
                    ->orWhereColumn('times_used', '<', 'max_uses');
            });
    }

    /**
     * Check if this discount code can be used by a specific user.
     */
    public function isValidForUser(User $user): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->times_used >= $this->max_uses) {
            return false;
        }

        if ($this->max_uses_per_user !== null) {
            $userUses = $this->orders()
                ->where('user_id', $user->id)
                ->count();

            if ($userUses >= $this->max_uses_per_user) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if this discount code applies to a given product.
     * If the code has no product restrictions, it applies to all products.
     */
    public function appliesToProduct(Product $product): bool
    {
        if ($this->products()->count() === 0) {
            return true;
        }

        return $this->products()->where('products.id', $product->id)->exists();
    }

    /**
     * Calculate the discount amount for a given subtotal (in cents).
     */
    public function calculateDiscount(int $subtotal): int
    {
        $discount = match ($this->type) {
            DiscountType::Percentage => (int) round($subtotal * $this->value / 100),
            DiscountType::FixedAmount => $this->value,
        };

        return min($discount, $subtotal);
    }

    /**
     * Get a human-readable description of this discount.
     */
    public function formattedValue(): string
    {
        return match ($this->type) {
            DiscountType::Percentage => $this->value.'%',
            DiscountType::FixedAmount => '$'.number_format($this->value / 100, 2),
        };
    }
}
