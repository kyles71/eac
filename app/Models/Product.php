<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

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
        return '$'.number_format($this->price / 100, 2);
    }
}
