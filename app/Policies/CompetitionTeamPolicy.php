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

    public function restore(AuthUser $user, CompetitionTeam $competitionTeam): bool
    {
        return $user->can('Restore:CompetitionTeam');
    }

    public function forceDelete(AuthUser $user, CompetitionTeam $competitionTeam): bool
    {
        return $user->can('ForceDelete:CompetitionTeam');
    }

    public function forceDeleteAny(AuthUser $user): bool
    {
        return $user->can('ForceDeleteAny:CompetitionTeam');
    }

    public function restoreAny(AuthUser $user): bool
    {
        return $user->can('RestoreAny:CompetitionTeam');
    }

    public function replicate(AuthUser $user, CompetitionTeam $competitionTeam): bool
    {
        return $user->can('Replicate:CompetitionTeam');
    }

    public function reorder(AuthUser $user): bool
    {
        return $user->can('Reorder:CompetitionTeam');
    }
}
