<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

final class CompetitionTeamStaff extends Pivot
{
    public $incrementing = true;

    protected $table = 'competition_team_user';

    protected static function booted(): void
    {
        self::creating(function (CompetitionTeamStaff $membership): void {
            $membership->ensureSeasonIsEditable();
            $membership->ensureStaffHasRole();
        });
        self::deleting(fn (CompetitionTeamStaff $membership) => $membership->ensureSeasonIsEditable());
    }

    private function ensureSeasonIsEditable(): void
    {
        if (! CompetitionTeam::query()
            ->whereKey($this->competition_team_id)
            ->whereHas('season', fn (Builder $query): Builder => CompetitionSeason::constrainToNotEnded($query))
            ->exists()) {
            throw ValidationException::withMessages([
                'competition_team' => 'The roster for an ended competition season cannot be changed.',
            ]);
        }
    }

    private function ensureStaffHasRole(): void
    {
        if (User::query()->whereKey($this->user_id)->whereHas('roles')->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'user_id' => 'Competition staff must have at least one staff role.',
        ]);
    }
}
