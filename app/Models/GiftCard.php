<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GiftCard extends Model
{
    /** @use HasFactory<\Database\Factories\GiftCardFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'initial_amount' => 'integer',
        'remaining_amount' => 'integer',
        'purchased_by_user_id' => 'integer',
        'redeemed_by_user_id' => 'integer',
        'order_id' => 'integer',
        'is_active' => 'boolean',
        'redeemed_at' => 'datetime',
    ];

    public function purchasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchased_by_user_id');
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Whether this gift card has already been redeemed.
     */
    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    /**
     * Whether this gift card can be redeemed.
     */
    public function isRedeemable(): bool
    {
        return $this->is_active && ! $this->isRedeemed() && $this->remaining_amount > 0;
    }

    /**
     * Get formatted initial amount in dollars.
     */
    public function formattedInitialAmount(): string
    {
        return '$'.number_format($this->initial_amount / 100, 2);
    }

    /**
     * Get formatted remaining amount in dollars.
     */
    public function formattedRemainingAmount(): string
    {
        return '$'.number_format($this->remaining_amount / 100, 2);
    }
}
