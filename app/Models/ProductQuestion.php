<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductQuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProductQuestion extends Model
{
    /** @use HasFactory<\Database\Factories\ProductQuestionFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'product_id' => 'integer',
        'type' => ProductQuestionType::class,
        'is_required' => 'boolean',
        'max_length' => 'integer',
        'options' => 'array',
        'allows_other' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<ProductQuestionAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(ProductQuestionAnswer::class);
    }
}
