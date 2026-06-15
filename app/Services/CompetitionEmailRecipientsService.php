<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Student;
use App\Models\User;

final class CompetitionEmailRecipientsService
{
    /**
     * @return array<int, Student|User>
     */
    public function forSeason(CompetitionSeason $season): array
    {
        return $this->recipients(
            Student::query()
                ->whereHas('competitionTeams', fn ($query) => $query->where('competition_season_id', $season->id))
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->all(),
            User::query()
                ->whereHas('competitionTeams', fn ($query) => $query->where('competition_season_id', $season->id))
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->all(),
        );
    }

    /**
     * @return array<int, Student|User>
     */
    public function forTeam(CompetitionTeam $team): array
    {
        return $this->recipients(
            $team->students()
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->all(),
            $team->staff()
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->all(),
        );
    }

    /**
     * @param  array<int, Student>  $students
     * @param  array<int, User>  $staff
     * @return array<int, Student|User>
     */
    private function recipients(array $students, array $staff): array
    {
        return [...$students, ...$staff];
    }
}
