<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EventTeacherAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventTeacherAssignment extends Model
{
    /** @use HasFactory<EventTeacherAssignmentFactory> */
    use HasFactory;

    /** @var array<string, string> */
    protected $casts = [
        'event_id' => 'integer',
        'teacher_id' => 'integer',
    ];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
