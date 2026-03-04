<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmergencyContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmergencyContact extends Model
{
    /** @use HasFactory<EmergencyContactFactory> */
    use HasFactory;

    public function studentWaiver(): BelongsTo
    {
        return $this->belongsTo(StudentWaiver::class);
    }
}
