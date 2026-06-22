<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CompetitionTeam;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class CompetitionTeamPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $user): bool
    {
        return $user->can('ViewAny:CompetitionTeam');
    }

    public function view(AuthUser $user, CompetitionTeam $competitionTeam): bool
    {
        return $user->can('View:CompetitionTeam');
    }

    public function create(AuthUser $user): bool
    {
        return $user->can('Create:CompetitionTeam');
    }

    public function update(AuthUser $user, CompetitionTeam $competitionTeam): bool
    {
        return ! $competitionTeam->hasEnded()
            && $user->can('Update:CompetitionTeam');
    }

    public function delete(AuthUser $user, CompetitionTeam $competitionTeam): bool
    {
        return $competitionTeam->canBeDeleted()
            && $user->can('Delete:CompetitionTeam');
    }

    public function deleteAny(AuthUser $user): bool
    {
        return $user->can('DeleteAny:CompetitionTeam');
    }
}
