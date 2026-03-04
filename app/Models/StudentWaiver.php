<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StudentWaiverFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

final class StudentWaiver extends Model
{
    /** @use HasFactory<StudentWaiverFactory> */
    use HasFactory;

    public function userForm(): MorphOne
    {
        return $this->morphOne(FormUser::class, 'responseable');
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }
}
