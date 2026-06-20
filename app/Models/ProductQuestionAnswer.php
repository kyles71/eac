<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductQuestionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductQuestionAnswer extends Model
{
    /** @use HasFactory<\Database\Factories\ProductQuestionAnswerFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'order_item_id' => 'integer',
        'product_question_id' => 'integer',
        'unit_number' => 'integer',
        'question_type' => ProductQuestionType::class,
        'was_required' => 'boolean',
        'question_order' => 'integer',
    ];

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<ProductQuestion, $this> */
    public function productQuestion(): BelongsTo
    {
        return $this->belongsTo(ProductQuestion::class);
    }

    public function formattedAnswer(): string
    {
        if ($this->selected_option === 'Other') {
            return $this->answer ?? 'Other';
        }

        return $this->selected_option
            ?? $this->answer
            ?? 'Not answered';
    }
}
