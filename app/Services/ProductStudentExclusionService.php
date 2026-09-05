<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Costume;
use App\Models\Product;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final readonly class ProductStudentExclusionService
{
    /** @param list<int|string> $studentIds */
    public function sync(Product $product, array $studentIds): void
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));

        if (! $product->is_purchase_required || $studentIds === []) {
            $product->excludedStudents()->sync([]);

            return;
        }

        $validStudentIds = $this->eligibleStudentsQuery($product)
            ->whereKey($studentIds)
            ->pluck('students.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if (count($validStudentIds) !== count($studentIds)) {
            throw ValidationException::withMessages([
                'excludedStudents' => 'Every excluded student must be part of the product purchase audience.',
            ]);
        }

        $product->excludedStudents()->sync($studentIds);
    }

    /** @return Builder<Student> */
    public function eligibleStudentsQuery(Product $product): Builder
    {
        $product->loadMissing('productable');

        if ($product->productable instanceof Costume) {
            return Student::query()->where(function (Builder $query) use ($product): void {
                $query
                    ->whereHas('enrollments', fn (Builder $query): Builder => $query
                        ->where('course_id', $product->productable->course_id))
                    ->orWhereIn('students.id', $product->excludedStudents()->select('students.id'));
            });
        }

        $courseIds = $product->requiredCourses()->pluck('courses.id');
        $teamIds = $product->requiredCompetitionTeams()->pluck('competition_teams.id');
        $assignedStudentIds = $product->assignedStudents()->pluck('students.id');
        $savedExcludedStudentIds = $product->excludedStudents()->pluck('students.id');
        $hasStudentAudience = $courseIds->isNotEmpty()
            || $teamIds->isNotEmpty()
            || $assignedStudentIds->isNotEmpty();
        $hasAnyAudience = $hasStudentAudience || $product->assignedUsers()->exists();

        return Student::query()->where(function (Builder $query) use (
            $assignedStudentIds,
            $courseIds,
            $hasAnyAudience,
            $savedExcludedStudentIds,
            $teamIds,
        ): void {
            $query->whereRaw('1 = 0');

            if ($assignedStudentIds->isNotEmpty()) {
                $query->orWhereKey($assignedStudentIds);
            }

            if ($courseIds->isNotEmpty()) {
                $query->orWhereHas('enrollments', fn (Builder $query): Builder => $query
                    ->whereIn('course_id', $courseIds));
            }

            if ($teamIds->isNotEmpty()) {
                $query->orWhereHas('competitionTeams', fn (Builder $query): Builder => $query
                    ->whereKey($teamIds));
            }

            if (! $hasAnyAudience) {
                $query->orWhereHas('enrollments.course.academicTerm', fn (Builder $query): Builder => $query->current());
            }

            if ($savedExcludedStudentIds->isNotEmpty()) {
                $query->orWhereKey($savedExcludedStudentIds);
            }
        });
    }
}
