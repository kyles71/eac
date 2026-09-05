<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\CompetitionSeason;
use App\Models\Costume;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final readonly class ProductAudienceService
{
    public function includes(Product $product, User $user, ?CarbonInterface $at = null): bool
    {
        return $this->applyToQuery(
            Product::query()->whereKey($product->getKey()),
            $user,
            $at,
        )->exists();
    }

    /**
     * A Product with no configured audience is available to everyone. Course and
     * Competition Team requirements are cumulative, while a direct User or
     * Student assignment overrides those requirements.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function applyToQuery(
        Builder $query,
        User $user,
        ?CarbonInterface $at = null,
    ): Builder {
        $at = $this->comparisonTime($at);

        return $query->where(function (Builder $query) use ($at, $user): void {
            $query
                ->where(function (Builder $query) use ($at, $user): void {
                    $query
                        ->whereDoesntHave('requiredCourses')
                        ->whereDoesntHave('requiredCompetitionTeams')
                        ->whereDoesntHave('assignedUsers')
                        ->whereDoesntHave('assignedStudents')
                        ->where(function (Builder $query) use ($at, $user): void {
                            $query
                                ->where('is_purchase_required', false)
                                ->orWhere('productable_type', Costume::class)
                                ->orWhereExists($this->currentTermStudentEnrollmentQuery($user, $at));
                        });
                })
                ->orWhereHas(
                    'assignedUsers',
                    fn (Builder $query): Builder => $query->whereKey($user->getKey()),
                )
                ->orWhereHas(
                    'assignedStudents',
                    fn (Builder $query): Builder => $this->applyNonExcludedStudentToQuery(
                        $query->where('students.user_id', $user->getKey()),
                    ),
                )
                ->orWhere(function (Builder $query) use ($at, $user): void {
                    $query
                        ->where(function (Builder $query): void {
                            $query
                                ->whereHas('requiredCourses')
                                ->orWhereHas('requiredCompetitionTeams');
                        })
                        ->where(function (Builder $query) use ($user): void {
                            $query
                                ->whereDoesntHave('requiredCourses')
                                ->orWhereHas(
                                    'requiredCourses',
                                    fn (Builder $query): Builder => $query
                                        ->whereExists($this->matchingCourseEnrollmentQuery($user)),
                                );
                        })
                        ->where(function (Builder $query) use ($at, $user): void {
                            $query
                                ->whereDoesntHave('requiredCompetitionTeams')
                                ->orWhereHas(
                                    'requiredCompetitionTeams',
                                    fn (Builder $query): Builder => $this->applyMatchingCompetitionTeamToQuery($query, $user, $at),
                                );
                        });
                });
        });
    }

    private function applyMatchingCompetitionTeamToQuery(
        Builder $query,
        User $user,
        CarbonInterface $at,
    ): Builder {
        return $query
            ->whereHas(
                'season',
                fn (Builder $query): Builder => CompetitionSeason::constrainToNotEnded($query, $at),
            )
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->whereHas(
                        'staff',
                        fn (Builder $query): Builder => $query->whereKey($user->getKey()),
                    )
                    ->orWhereHas(
                        'students',
                        fn (Builder $query): Builder => $this->applyNonExcludedStudentToQuery(
                            $query->where('students.user_id', $user->getKey()),
                        ),
                    );
            });
    }

    /** @param Builder<\App\Models\Student> $query */
    private function applyNonExcludedStudentToQuery(Builder $query): Builder
    {
        return $query->whereNotExists(fn (QueryBuilder $query): QueryBuilder => $query
            ->from('product_student_exclusion')
            ->selectRaw('1')
            ->whereColumn('product_student_exclusion.product_id', 'products.id')
            ->whereColumn('product_student_exclusion.student_id', 'students.id'));
    }

    private function matchingCourseEnrollmentQuery(User $user): QueryBuilder
    {
        return DB::table('enrollments')
            ->selectRaw('1')
            ->whereColumn('enrollments.course_id', 'courses.id')
            ->where('enrollments.user_id', $user->getKey())
            ->where(function (QueryBuilder $query): void {
                $query
                    ->whereNull('enrollments.student_id')
                    ->orWhereNotExists(fn (QueryBuilder $query): QueryBuilder => $query
                        ->from('product_student_exclusion')
                        ->selectRaw('1')
                        ->whereColumn('product_student_exclusion.product_id', 'products.id')
                        ->whereColumn('product_student_exclusion.student_id', 'enrollments.student_id'));
            });
    }

    private function currentTermStudentEnrollmentQuery(User $user, CarbonInterface $at): QueryBuilder
    {
        $comparisonDate = AcademicTerm::comparisonDate($at);

        return DB::table('enrollments')
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->join('academic_terms', 'academic_terms.id', '=', 'courses.academic_term_id')
            ->selectRaw('1')
            ->where('enrollments.user_id', $user->getKey())
            ->whereNotNull('enrollments.student_id')
            ->whereDate('academic_terms.starts_on', '<=', $comparisonDate)
            ->whereDate('academic_terms.ends_on', '>=', $comparisonDate)
            ->whereNotExists(fn (QueryBuilder $query): QueryBuilder => $query
                ->from('product_student_exclusion')
                ->selectRaw('1')
                ->whereColumn('product_student_exclusion.product_id', 'products.id')
                ->whereColumn('product_student_exclusion.student_id', 'enrollments.student_id'));
    }

    private function comparisonTime(?CarbonInterface $at): CarbonInterface
    {
        $timezone = (string) config('app.timezone', 'UTC');

        return CarbonImmutable::instance($at ?? now())->setTimezone($timezone);
    }
}
