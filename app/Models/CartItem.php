<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CartItem extends Model
{
    /** @use HasFactory<\Database\Factories\CartItemFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'integer',
        'custom_gift_card_amount' => 'integer',
        'question_answers' => 'array',
        'reminder_sent_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customGiftCardAmount(): ?int
    {
        return $this->custom_gift_card_amount > 0 ? $this->custom_gift_card_amount : null;
    }

    /** @return array<int, array<string, string|null>> */
    public function storedQuestionAnswers(): array
    {
        return is_array($this->question_answers) ? $this->question_answers : [];
    }

    public function effectiveUnitPrice(): int
    {
        return $this->customGiftCardAmount() ?? $this->product->price ?? 0;
    }

    public function lineTotal(): int
    {
        return $this->effectiveUnitPrice() * $this->quantity;
    }

    public function formattedEffectiveUnitPrice(): string
    {
        return format_money($this->effectiveUnitPrice());
    }

    public function formattedLineTotal(): string
    {
        return format_money($this->lineTotal());
    }
}
