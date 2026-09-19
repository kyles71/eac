<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InstallmentPaymentAttemptAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\InstallmentPaymentAttemptAllocationFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'installment_payment_attempt_id' => 'integer',
        'installment_id' => 'integer',
        'amount' => 'integer',
    ];

    /** @return BelongsTo<InstallmentPaymentAttempt, $this> */
    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(InstallmentPaymentAttempt::class, 'installment_payment_attempt_id');
    }

    /** @return BelongsTo<Installment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }
}
