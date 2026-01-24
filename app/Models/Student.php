<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Database\Eloquent\Attributes\Scope;
// use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

// use Illuminate\Database\Eloquent\Relations\HasOne;
// use Staudenmeir\EloquentHasManyDeep\HasManyDeep;
// use Staudenmeir\EloquentHasManyDeep\HasRelationships;

final class Student extends Model
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
        'user_id' => 'integer',
    ];

    public function fullName(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes): string => $attributes['first_name'].' '.$attributes['last_name']
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'enrollments');
    }

    public function events(): MorphMany
    {
        return $this->morphMany(EventAttendee::class, 'attendee');
    }

    // #[Scope]
    // protected function healthInfoNeedsUpdating(Builder $query): void
    // {
    //     $query->where(function ($q) {
    //         $q->doesntHave('healthInfo')
    //             ->orWhereHas('healthInfo', function (Builder $q2) {
    //                 $q2->where('updated_at', '<', now()->subYear());
    //             });
    //     });
    // }

    // public function emergencyContacts(): HasMany
    // {
    //     return $this->hasMany(StudentEmergencyContact::class);
    // }

    // public function healthInfo(): HasOne
    // {
    //     return $this->hasOne(StudentHealthInfo::class);
    // }
}
