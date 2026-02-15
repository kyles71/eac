<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyContact extends Model
{
    public function studentWaiver(): BelongsTo
    {
        return $this->belongsTo(StudentWaiver::class);
    }
}
