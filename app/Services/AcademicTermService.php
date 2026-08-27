<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CourseSemester;
use App\Models\AcademicTerm;
use App\Settings\AcademicTermSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AcademicTermService
{
    public function __construct(private AcademicTermSettings $settings) {}

    public function sync(?CarbonInterface $date = null): int
    {
        $comparisonDate = AcademicTerm::comparisonDate($date);
        $currentYear = (int) mb_substr($comparisonDate, 0, 4);
        $changes = [];

        foreach ([$currentYear, $currentYear + 1] as $year) {
            foreach (CourseSemester::cases() as $semester) {
                $academicTerm = AcademicTerm::query()->firstOrNew([
                    'semester' => $semester,
                    'year' => $year,
                ]);

                if ($academicTerm->exists && (
                    ! $academicTerm->uses_default_dates
                    || $academicTerm->starts_on->toDateString() <= $comparisonDate
                )) {
                    continue;
                }

                $academicTerm->fill([
                    ...$this->defaultDates($semester, $year),
                    'uses_default_dates' => true,
                ]);

                if ($academicTerm->isDirty()) {
                    $changes[] = $academicTerm;
                }
            }
        }

        $this->validateChanges($changes);

        DB::transaction(function () use ($changes): void {
            AcademicTerm::withoutEvents(function () use ($changes): void {
                foreach ($changes as $academicTerm) {
                    $academicTerm->save();
                }
            });
        });

        return count($changes);
    }

    /**
     * @return array{starts_on: string, ends_on: string}
     */
    public function defaultDates(CourseSemester $semester, int $year): array
    {
        $winterSpringStartsOn = $this->startDate($year, $this->settings->winter_spring_starts_on);
        $summerStartsOn = $this->startDate($year, $this->settings->summer_starts_on);
        $fallStartsOn = $this->startDate($year, $this->settings->fall_starts_on);
        $nextWinterSpringStartsOn = $this->startDate($year + 1, $this->settings->winter_spring_starts_on);

        return match ($semester) {
            CourseSemester::WinterSpring => [
                'starts_on' => $winterSpringStartsOn->toDateString(),
                'ends_on' => $summerStartsOn->subDay()->toDateString(),
            ],
            CourseSemester::Summer => [
                'starts_on' => $summerStartsOn->toDateString(),
                'ends_on' => $fallStartsOn->subDay()->toDateString(),
            ],
            CourseSemester::Fall => [
                'starts_on' => $fallStartsOn->toDateString(),
                'ends_on' => $nextWinterSpringStartsOn->subDay()->toDateString(),
            ],
        };
    }

    private function startDate(int $year, string $monthAndDay): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $year.'-'.$monthAndDay,
            (string) config('app.display_timezone', config('app.timezone')),
        );
    }

    /**
     * @param  array<int, AcademicTerm>  $changes
     */
    private function validateChanges(array $changes): void
    {
        $changedIds = array_values(array_map(
            fn (AcademicTerm $academicTerm): int => (int) $academicTerm->getKey(),
            array_filter(
                $changes,
                fn (AcademicTerm $academicTerm): bool => $academicTerm->exists,
            ),
        ));
        $preservedTerms = AcademicTerm::query()
            ->when($changedIds !== [], fn ($query) => $query->whereKeyNot($changedIds))
            ->get();
        $proposedTerms = collect($changes);

        foreach ($proposedTerms as $index => $academicTerm) {
            if ($academicTerm->starts_on->gt($academicTerm->ends_on)) {
                throw ValidationException::withMessages([
                    'academic_terms' => 'The recurring academic term dates are not in chronological order.',
                ]);
            }

            $overlapsPreservedTerm = $preservedTerms->contains(
                fn (AcademicTerm $preservedTerm): bool => $this->overlaps($academicTerm, $preservedTerm),
            );
            $overlapsProposedTerm = $proposedTerms
                ->slice($index + 1)
                ->contains(fn (AcademicTerm $proposedTerm): bool => $this->overlaps($academicTerm, $proposedTerm));

            if ($overlapsPreservedTerm || $overlapsProposedTerm) {
                throw ValidationException::withMessages([
                    'academic_terms' => 'The recurring academic term dates overlap an existing term.',
                ]);
            }
        }
    }

    private function overlaps(AcademicTerm $first, AcademicTerm $second): bool
    {
        return $first->starts_on->lte($second->ends_on)
            && $first->ends_on->gte($second->starts_on);
    }
}
