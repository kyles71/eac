<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditGrantStatus;
use App\Enums\CreditTransactionType;
use App\Enums\ProductType;
use Database\Factories\CreditGrantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class CreditGrant extends Model
{
    /** @use HasFactory<CreditGrantFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'granted_by_user_id' => 'integer',
        'source_id' => 'integer',
        'initial_amount' => 'integer',
        'remaining_amount' => 'integer',
        'restricted_to_product_type' => ProductType::class,
        'has_product_restrictions' => 'boolean',
        'expires_on' => 'date',
        'revoked_at' => 'datetime',
        'revoked_by_user_id' => 'integer',
    ];

    /** @param Builder<CreditGrant> $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->usable()->where('remaining_amount', '>', 0);
    }

    /** @param Builder<CreditGrant> $query */
    public function scopeUsable(Builder $query): void
    {
        $query
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('expires_on')
                    ->orWhereDate('expires_on', '>=', now('America/New_York')->toDateString());
            });
    }

    /** @param Builder<CreditGrant> $query */
    public function scopeUnrestricted(Builder $query): void
    {
        $query
            ->whereNull('restricted_to_product_type')
            ->where('has_product_restrictions', false);
    }

    /** @param Builder<CreditGrant> $query */
    public function scopeRestricted(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->whereNotNull('restricted_to_product_type')
                ->orWhere('has_product_restrictions', true);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }

    /** @return HasMany<CreditTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class)->latest();
    }

    public function status(): CreditGrantStatus
    {
        if ($this->revoked_at !== null) {
            return CreditGrantStatus::Revoked;
        }

        if ($this->expires_on !== null
            && $this->expires_on->toDateString() < now('America/New_York')->toDateString()) {
            return CreditGrantStatus::Expired;
        }

        if ($this->remaining_amount <= 0) {
            return CreditGrantStatus::Depleted;
        }

        return CreditGrantStatus::Active;
    }

    public function hasRestrictions(): bool
    {
        return $this->restricted_to_product_type !== null || $this->has_product_restrictions;
    }

    public function appliesToProduct(Product $product): bool
    {
        if ($this->restricted_to_product_type !== null
            && ProductType::fromProductableType($product->productable_type) !== $this->restricted_to_product_type) {
            return false;
        }

        if ($this->has_product_restrictions) {
            return $this->products->contains($product);
        }

        return true;
    }

    public function restrictionSummary(): string
    {
        if (! $this->hasRestrictions()) {
            return 'Unrestricted';
        }

        $parts = [];

        if ($this->restricted_to_product_type !== null) {
            $parts[] = $this->restricted_to_product_type->getLabel().' products only';
        }

        $productCount = $this->has_product_restrictions ? $this->products()->count() : 0;

        if ($this->has_product_restrictions) {
            $parts[] = $productCount.' '.($productCount === 1 ? 'product' : 'products');
        }

        return implode(', ', $parts);
    }

    public function netUsedAmount(): int
    {
        return max(0, -(int) $this->transactions()
            ->whereIn('type', [CreditTransactionType::CheckoutDebit, CreditTransactionType::Refund])
            ->sum('amount'));
    }

    public function availableAmount(): int
    {
        return $this->status() === CreditGrantStatus::Active ? $this->remaining_amount : 0;
    }

    public function expiredUnusedAmount(): int
    {
        return $this->status() === CreditGrantStatus::Expired ? $this->remaining_amount : 0;
    }

    public function revokedUnusedAmount(): int
    {
        return $this->status() === CreditGrantStatus::Revoked ? $this->remaining_amount : 0;
    }
}
