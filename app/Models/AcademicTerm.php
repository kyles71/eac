<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseSemester;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property-read string $display_name
 */
final class AcademicTerm extends Model
{
    /** @use HasFactory<\Database\Factories\AcademicTermFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'academic_year_id' => 'integer',
        'semester' => CourseSemester::class,
        'year' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'uses_default_dates' => 'boolean',
        'target_enrollments' => 'integer',
        'stretch_goal_enrollments' => 'integer',
    ];

    public static function comparisonDate(?CarbonInterface $date = null): string
    {
        return ($date ?? Carbon::now())
            ->copy()
            ->timezone((string) config('app.display_timezone', config('app.timezone')))
            ->toDateString();
    }

    public function displayName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->semester->getLabel().' '.$this->year,
        );
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return HasMany<Course, $this> */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function scopeCurrent(Builder $query, ?CarbonInterface $date = null): void
    {
        $comparisonDate = self::comparisonDate($date);

        $query
            ->whereDate('starts_on', '<=', $comparisonDate)
            ->whereDate('ends_on', '>=', $comparisonDate);
    }

    public function isCurrent(?CarbonInterface $date = null): bool
    {
        $comparisonDate = self::comparisonDate($date);

        return $this->starts_on->toDateString() <= $comparisonDate
            && $this->ends_on->toDateString() >= $comparisonDate;
    }

    public function isUpcoming(?CarbonInterface $date = null): bool
    {
        return $this->starts_on->toDateString() > self::comparisonDate($date);
    }

    public function hasEnded(?CarbonInterface $date = null): bool
    {
        return $this->ends_on->toDateString() < self::comparisonDate($date);
    }

    public function status(): string
    {
        if ($this->hasEnded()) {
            return 'Ended';
        }

        return $this->isCurrent() ? 'Current' : 'Upcoming';
    }

    public function canBeDeleted(): bool
    {
        return ! $this->courses()->exists();
    }

    protected static function booted(): void
    {
        self::saving(function (AcademicTerm $academicTerm): void {
            $academicTerm->academic_year_id = AcademicYear::forTerm(
                $academicTerm->semester,
                $academicTerm->year,
            )->id;
            $academicTerm->validateDateRange();
            $academicTerm->validateEnrollmentGoals();
            $academicTerm->validateNoOverlap();
        });

        self::deleting(function (AcademicTerm $academicTerm): void {
            if ($academicTerm->canBeDeleted()) {
                return;
            }

            throw ValidationException::withMessages([
                'academic_term' => 'Academic terms assigned to courses cannot be deleted.',
            ]);
        });
    }

    private function validateDateRange(): void
    {
        if ($this->starts_on->lte($this->ends_on)) {
            return;
        }

        throw ValidationException::withMessages([
            'ends_on' => 'The end date must be on or after the start date.',
        ]);
    }

    private function validateEnrollmentGoals(): void
    {
        if ($this->target_enrollments !== null && $this->target_enrollments < 0) {
            throw ValidationException::withMessages([
                'target_enrollments' => 'The target enrollment goal must be zero or greater.',
            ]);
        }

        if ($this->stretch_goal_enrollments !== null && $this->stretch_goal_enrollments < 0) {
            throw ValidationException::withMessages([
                'stretch_goal_enrollments' => 'The stretch enrollment goal must be zero or greater.',
            ]);
        }

        if ($this->target_enrollments !== null
            && $this->stretch_goal_enrollments !== null
            && $this->stretch_goal_enrollments < $this->target_enrollments) {
            throw ValidationException::withMessages([
                'stretch_goal_enrollments' => 'The stretch goal must be at least the target goal.',
            ]);
        }
    }

    private function validateNoOverlap(): void
    {
        $overlappingTerm = self::query()
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->getKey()))
            ->whereDate('starts_on', '<=', $this->ends_on->toDateString())
            ->whereDate('ends_on', '>=', $this->starts_on->toDateString())
            ->first();

        if (! $overlappingTerm instanceof self) {
            return;
        }

        if ($this->uses_default_dates) {
            throw ValidationException::withMessages([
                'uses_default_dates' => sprintf(
                    'The recurring default dates overlap %s (%s–%s). Turn off recurring defaults or adjust the overlapping term first.',
                    $overlappingTerm->display_name,
                    $overlappingTerm->starts_on->format('M j, Y'),
                    $overlappingTerm->ends_on->format('M j, Y'),
                ),
            ]);
        }

        throw ValidationException::withMessages([
            'starts_on' => 'Academic term dates cannot overlap another term.',
            'ends_on' => 'Academic term dates cannot overlap another term.',
        ]);
    }
}
