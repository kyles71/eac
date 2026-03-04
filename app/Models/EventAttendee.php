<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EventAttendeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class EventAttendee extends Model
{
    /** @use HasFactory<EventAttendeeFactory> */
    use HasFactory;

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
