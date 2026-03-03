<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmergencyContact extends Model
{
    public function studentWaiver(): BelongsTo
    {
        return $this->belongsTo(StudentWaiver::class);
    }
}
