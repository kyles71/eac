<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompetitionSeason;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

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
     * Competition Team requirements are cumulative, while a direct User
     * assignment overrides those requirements.
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
                ->where(function (Builder $query): void {
                    $query
                        ->whereDoesntHave('requiredCourses')
                        ->whereDoesntHave('requiredCompetitionTeams')
                        ->whereDoesntHave('assignedUsers');
                })
                ->orWhereHas(
                    'assignedUsers',
                    fn (Builder $query): Builder => $query->whereKey($user->getKey()),
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
                                        ->whereIn('courses.id', $user->enrollments()->select('course_id')),
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
                        fn (Builder $query): Builder => $query->where('students.user_id', $user->getKey()),
                    );
            });
    }

    private function comparisonTime(?CarbonInterface $at): CarbonInterface
    {
        $timezone = (string) config('app.timezone', 'UTC');

        return CarbonImmutable::instance($at ?? now())->setTimezone($timezone);
    }
}
