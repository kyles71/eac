<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// use Illuminate\Database\Eloquent\Relations\BelongsToMany;
// use Staudenmeir\EloquentHasManyDeep\HasManyDeep;
// use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Event extends Model
{
    use HasFactory;
    // use HasRelationships;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'course_id' => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }

    // public function students(): HasManyDeep
    // {
    //     return $this->hasManyDeepFromRelationsWithConstraints([$this, 'course'], [new Course(), 'students']);
    // }
}
