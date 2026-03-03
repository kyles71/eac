<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestrictedCredit extends Model
{
    /** @use HasFactory<\Database\Factories\RestrictedCreditFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'gift_card_type_id' => 'integer',
        'gift_card_id' => 'integer',
        'balance' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function giftCardType(): BelongsTo
    {
        return $this->belongsTo(GiftCardType::class);
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    /**
     * Get formatted balance in dollars.
     */
    public function formattedBalance(): string
    {
        return '$'.number_format($this->balance / 100, 2);
    }
}
