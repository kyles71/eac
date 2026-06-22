<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CalendarAudienceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class CalendarAudience extends Model
{
    /** @use HasFactory<CalendarAudienceFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'calendar_id' => 'integer',
        'audience_id' => 'integer',
    ];

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function audience(): MorphTo
    {
        return $this->morphTo();
    }
}
