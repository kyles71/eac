<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

final class CompetitionTeamStudent extends Pivot
{
    public $incrementing = true;

    protected static function booted(): void
    {
        self::creating(fn (CompetitionTeamStudent $membership) => $membership->ensureSeasonIsEditable());
        self::deleting(fn (CompetitionTeamStudent $membership) => $membership->ensureSeasonIsEditable());
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
}
