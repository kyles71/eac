<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseSemester;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/** @property-read string $display_name */
final class AcademicYear extends Model
{
    /** @use HasFactory<\Database\Factories\AcademicYearFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'starts_in_year' => 'integer',
    ];

    public static function startingYearFor(CourseSemester $semester, int $calendarYear): int
    {
        return $semester === CourseSemester::Fall ? $calendarYear : $calendarYear - 1;
    }

    public static function forTerm(CourseSemester $semester, int $calendarYear): self
    {
        return self::query()->firstOrCreate([
            'starts_in_year' => self::startingYearFor($semester, $calendarYear),
        ]);
    }

    public function displayName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->starts_in_year.'–'.mb_substr((string) ($this->starts_in_year + 1), -2),
        );
    }

    /** @return HasMany<AcademicTerm, $this> */
    public function terms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class);
    }

    protected static function booted(): void
    {
        self::deleting(function (AcademicYear $academicYear): void {
            if (! $academicYear->terms()->exists()) {
                return;
            }

            throw ValidationException::withMessages([
                'academic_year' => 'Academic years containing terms cannot be deleted.',
            ]);
        });
    }
}
