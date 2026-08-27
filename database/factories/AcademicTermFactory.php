<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CourseSemester;
use App\Models\AcademicTerm;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicTerm>
 */
final class AcademicTermFactory extends Factory
{
    private static int $nextYear = 5000;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = self::$nextYear++;

        return [
            'semester' => CourseSemester::Fall,
            'year' => $year,
            'starts_on' => CarbonImmutable::create($year, 9, 1),
            'ends_on' => CarbonImmutable::create($year, 12, 31),
            'uses_default_dates' => true,
        ];
    }

    public function forSemester(CourseSemester $semester, int $year): static
    {
        return $this->state(fn (): array => [
            'semester' => $semester,
            'year' => $year,
            ...$this->datesFor($semester, $year),
        ]);
    }

    /** @return array{starts_on: CarbonImmutable, ends_on: CarbonImmutable} */
    private function datesFor(CourseSemester $semester, int $year): array
    {
        return match ($semester) {
            CourseSemester::WinterSpring => [
                'starts_on' => CarbonImmutable::create($year, 1, 1),
                'ends_on' => CarbonImmutable::create($year, 5, 31),
            ],
            CourseSemester::Summer => [
                'starts_on' => CarbonImmutable::create($year, 6, 1),
                'ends_on' => CarbonImmutable::create($year, 8, 31),
            ],
            CourseSemester::Fall => [
                'starts_on' => CarbonImmutable::create($year, 9, 1),
                'ends_on' => CarbonImmutable::create($year, 12, 31),
            ],
        };
    }
}
