<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CompetitionSeason;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class CompetitionSeasonPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $user): bool
    {
        return $user->can('ViewAny:CompetitionSeason');
    }

    public function view(AuthUser $user, CompetitionSeason $competitionSeason): bool
    {
        return $user->can('View:CompetitionSeason');
    }

    public function create(AuthUser $user): bool
    {
        return $user->can('Create:CompetitionSeason');
    }

    public function update(AuthUser $user, CompetitionSeason $competitionSeason): bool
    {
        return ! $competitionSeason->hasEnded()
            && $user->can('Update:CompetitionSeason');
    }

    public function delete(AuthUser $user, CompetitionSeason $competitionSeason): bool
    {
        return $competitionSeason->canBeDeleted()
            && $user->can('Delete:CompetitionSeason');
    }

    public function deleteAny(AuthUser $user): bool
    {
        return $user->can('DeleteAny:CompetitionSeason');
    }
}
