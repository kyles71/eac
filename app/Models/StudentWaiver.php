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

    /** @return HasMany<EmergencyContact, $this> */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'medical_release_consent' => 'boolean',
            'medical_release_signed_on' => 'date',
            'health_safety_policy_consent' => 'boolean',
            'health_safety_policy_signed_on' => 'date',
            'media_release_consent' => 'boolean',
            'media_release_signed_on' => 'date',
        ];
    }
}
