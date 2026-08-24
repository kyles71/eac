<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FirstAidType;
use App\Enums\StopLightColor;
use App\Enums\StudentCommunicationType;
use Database\Factories\StudentCommunicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StudentCommunication extends Model
{
    /** @use HasFactory<StudentCommunicationFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'student_id' => 'integer',
        'event_id' => 'integer',
        'author_id' => 'integer',
        'type' => StudentCommunicationType::class,
        'first_aid_type' => FirstAidType::class,
        'stop_light_color' => StopLightColor::class,
        'occurred_at' => 'datetime',
        'recipient_emails' => 'array',
        'queued_at' => 'datetime',
    ];

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
