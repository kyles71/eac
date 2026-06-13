<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompetitionTeam;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class CompetitionRosterService
{
    public function isCurrentMember(User $user, ?CarbonInterface $date = null): bool
    {
        return $this->applyCurrentAccountScope(User::query()->whereKey($user->getKey()), $date)->exists();
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function applyCurrentAccountScope(Builder $query, ?CarbonInterface $date = null): Builder
    {
        return $query->where(function (Builder $query) use ($date): void {
            $query
                ->whereHas('competitionTeams', fn (Builder $query): Builder => CompetitionTeam::constrainToCurrent($query, $date))
                ->orWhereHas('students.competitionTeams', fn (Builder $query): Builder => CompetitionTeam::constrainToCurrent($query, $date));
        });
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function applyRoleBearingScope(Builder $query): Builder
    {
        return $query->whereHas('roles');
    }
}
