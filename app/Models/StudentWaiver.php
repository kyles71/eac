<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class StudentWaiver extends Model
{
    public function userForm(): MorphOne
    {
        return $this->morphOne(FormUser::class, 'responseable');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }
}
