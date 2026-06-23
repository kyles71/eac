<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditTransactionType;
use Database\Factories\CreditTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class CreditTransaction extends Model
{
    /** @use HasFactory<CreditTransactionFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'credit_grant_id' => 'integer',
        'performed_by_user_id' => 'integer',
        'amount' => 'integer',
        'type' => CreditTransactionType::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creditGrant(): BelongsTo
    {
        return $this->belongsTo(CreditGrant::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
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

        return $prefix.format_money(abs($this->amount));
    }
}
