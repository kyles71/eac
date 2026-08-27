<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseSemester;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'semester' => CourseSemester::class,
        'year' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'uses_default_dates' => 'boolean',
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
            $academicTerm->validateDateRange();
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

    private function validateNoOverlap(): void
    {
        $overlaps = self::query()
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->getKey()))
            ->whereDate('starts_on', '<=', $this->ends_on->toDateString())
            ->whereDate('ends_on', '>=', $this->starts_on->toDateString())
            ->exists();

        if (! $overlaps) {
            return;
        }

        throw ValidationException::withMessages([
            'starts_on' => 'Academic term dates cannot overlap another term.',
            'ends_on' => 'Academic term dates cannot overlap another term.',
        ]);
    }
}
