<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InstallmentDueDateAdjustment extends Model
{
    /** @use HasFactory<\Database\Factories\InstallmentDueDateAdjustmentFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'installment_id' => 'integer',
        'adjusted_by_user_id' => 'integer',
        'old_due_date' => 'date',
        'new_due_date' => 'date',
        'previous_status' => InstallmentStatus::class,
        'previous_retry_count' => 'integer',
    ];

    /** @return BelongsTo<Installment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by_user_id');
    }
}
