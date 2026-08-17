<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormTypes;
use App\Enums\MedicalWaiverStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Tags\HasTags;

/**
 * @property-read string $fullName
 */
final class Student extends Model
{
    use HasFactory, HasTags;

    public const string GENERAL_TAG_TYPE = 'student-general';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'birthdate' => 'date',
    ];

    public function fullName(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes): string => $attributes['first_name'].' '.$attributes['last_name']
        );
    }

    public function displayName(): string
    {
        return $this->fullName;
    }

    public function age(): Attribute
    {
        return Attribute::make(
            get: fn (): int => $this->birthdate->age,
        );
    }

    public function ageOn(CarbonInterface $date): int
    {
        return (int) $this->birthdate->diffInYears($date);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasOne<RecurringPrivateLesson, $this> */
    public function recurringPrivateLesson(): HasOne
    {
        return $this->hasOne(RecurringPrivateLesson::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'enrollments');
    }

    /** @return BelongsToMany<CompetitionTeam, $this, CompetitionTeamStudent, 'pivot'> */
    public function competitionTeams(): BelongsToMany
    {
        return $this->belongsToMany(CompetitionTeam::class)
            ->using(CompetitionTeamStudent::class)
            ->withTimestamps();
    }

    public function events(): MorphMany
    {
        return $this->morphMany(EventAttendee::class, 'attendee');
    }

    /** @return HasMany<FormUser, $this> */
    public function forms(): HasMany
    {
        return $this->hasMany(FormUser::class);
    }

    /** @return HasMany<StudentEmail, $this> */
    public function additionalEmails(): HasMany
    {
        return $this->hasMany(StudentEmail::class);
    }

    public function medicalWaiverStatus(): MedicalWaiverStatus
    {
        if ($this->latestValidCompletedMedicalWaiver() !== null) {
            return MedicalWaiverStatus::OnFile;
        }

        return $this->latestCompletedMedicalWaiver() !== null
            ? MedicalWaiverStatus::Expired
            : MedicalWaiverStatus::Missing;
    }

    public function currentMedicalWaiver(): ?FormUser
    {
        return $this->latestCompletedMedicalWaiver();
    }

    public function latestValidCompletedMedicalWaiver(): ?FormUser
    {
        return $this->completedMedicalWaivers()
            ->whereHas('form', fn (Builder $query): Builder => Form::applyActiveConstraint($query))
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    public function latestCompletedMedicalWaiver(): ?FormUser
    {
        return $this->completedMedicalWaivers()
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    public function pendingMedicalWaiver(): ?FormUser
    {
        return $this->forms()
            ->pending()
            ->whereHas('form', fn (Builder $query): Builder => Form::applyActiveConstraint(
                $query->where('form_type', FormTypes::StudentWaiver),
            ))
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    /** @return HasMany<FormUser, $this> */
    private function completedMedicalWaivers(): HasMany
    {
        return $this->forms()
            ->completed()
            ->whereHas('form', fn ($query) => $query->where('form_type', FormTypes::StudentWaiver));
    }
}
