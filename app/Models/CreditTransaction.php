<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class CreditTransaction extends Model
{
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'amount' => 'integer',
        'type' => CreditTransactionType::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the formatted amount in dollars (signed).
     */
    public function formattedAmount(): string
    {
        $prefix = $this->amount >= 0 ? '+' : '-';

        return $prefix.'$'.number_format(abs($this->amount) / 100, 2);
    }
}
