<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentPlanFrequency;
use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PaymentPlanTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentPlanTemplateFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'product_type' => ProductType::class,
        'course_semesters' => 'array',
        'min_price' => 'integer',
        'max_price' => 'integer',
        'number_of_installments' => 'integer',
        'frequency' => PaymentPlanFrequency::class,
        'is_active' => 'boolean',
    ];

    public function paymentPlans(): HasMany
    {
        return $this->hasMany(PaymentPlan::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Scope to only include active templates.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to templates matching a given product (by morph type and price).
     */
    public function scopeForProduct(Builder $query, ?string $productableType, int $price): void
    {
        $productType = ProductType::fromProductableType($productableType);

        $query->where(function (Builder $q) use ($productType): void {
            $q->where('product_type', ProductType::Any)
                ->orWhere('product_type', $productType);
        })
            ->where('min_price', '<=', $price)
            ->where('max_price', '>=', $price);
    }

    public function matchesCartItem(CartItem $cartItem): bool
    {
        return $this->matchesProduct($cartItem->product, $cartItem->lineTotal());
    }

    public function matchesProduct(Product $product, int $price): bool
    {
        $product->loadMissing('productable');

        if (! $product->allows_payment_plan) {
            return false;
        }

        $productType = ProductType::fromProductableType($product->productable_type);

        if (! in_array($this->product_type, [ProductType::Any, $productType], true)) {
            return false;
        }

        if ($this->min_price > $price || $this->max_price < $price) {
            return false;
        }

        return $this->matchesTypeSpecificRestrictions($product);
    }

    public function isUsed(): bool
    {
        return $this->orders()->exists() || $this->paymentPlans()->exists();
    }

    /**
     * @return list<string>
     */
    public function allowedCourseSemesters(): array
    {
        if ($this->product_type !== ProductType::Course) {
            return [];
        }

        return array_values(array_filter($this->course_semesters ?? []));
    }

    /**
     * Calculate the installment amount (in cents) for a given total.
     * First installment absorbs the rounding remainder.
     *
     * @return array{first: int, remaining: int}
     */
    public function installmentAmounts(int $total): array
    {
        $baseAmount = intdiv($total, $this->number_of_installments);
        $remainder = $total - ($baseAmount * $this->number_of_installments);

        return [
            'first' => $baseAmount + $remainder,
            'remaining' => $baseAmount,
        ];
    }

    private function matchesTypeSpecificRestrictions(Product $product): bool
    {
        if ($this->product_type !== ProductType::Course) {
            return true;
        }

        $allowedSemesters = $this->allowedCourseSemesters();

        if ($allowedSemesters === []) {
            return true;
        }

        if (! $product->productable instanceof Course) {
            return false;
        }

        $semester = $product->productable->semester->value;

        return in_array($semester, $allowedSemesters, true);
    }
}
