<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EventAttendee extends Model
{
    protected $casts = [
        'id' => 'integer',
        'event_id' => 'integer',
        'attendee_id' => 'integer',
        'attended' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function attendee(): MorphTo
    {
        return $this->morphTo();
    }
}
