<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

final class CompetitionTeam extends Model
{
    /** @use HasFactory<\Database\Factories\CompetitionTeamFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'competition_season_id' => 'integer',
    ];

    public static function constrainToCurrent(Builder $query, ?CarbonInterface $date = null): Builder
    {
        return $query->whereHas(
            'season',
            fn (Builder $query): Builder => CompetitionSeason::constrainToCurrent($query, $date),
        );
    }

    /** @return BelongsTo<CompetitionSeason, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(CompetitionSeason::class, 'competition_season_id');
    }

    /** @return BelongsToMany<Student, $this, CompetitionTeamStudent, 'pivot'> */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)
            ->using(CompetitionTeamStudent::class)
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this, CompetitionTeamStaff, 'pivot'> */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'competition_team_user')
            ->using(CompetitionTeamStaff::class)
            ->withTimestamps();
    }

    public function scopeCurrent(Builder $query, ?CarbonInterface $date = null): Builder
    {
        return self::constrainToCurrent($query, $date);
    }

    public function hasEnded(): bool
    {
        $this->loadMissing('season');

        return $this->season->hasEnded();
    }

    public function hasRosterHistory(): bool
    {
        return $this->students()->exists() || $this->staff()->exists();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->hasEnded() && ! $this->hasRosterHistory();
    }

    protected static function booted(): void
    {
        self::creating(function (CompetitionTeam $team): void {
            if ($team->hasEnded()) {
                throw ValidationException::withMessages([
                    'competition_team' => 'Teams cannot be added to ended competition seasons.',
                ]);
            }
        });

        self::updating(function (CompetitionTeam $team): void {
            $originalSeasonId = (int) $team->getRawOriginal('competition_season_id');
            $originalSeasonEnded = CompetitionSeason::query()
                ->whereKey($originalSeasonId)
                ->whereDate('ends_on', '<', CompetitionSeason::comparisonDate())
                ->exists();

            if ($originalSeasonEnded || $team->hasEnded()) {
                throw ValidationException::withMessages([
                    'competition_team' => 'Teams in ended competition seasons cannot be changed.',
                ]);
            }
        });

        self::deleting(function (CompetitionTeam $team): void {
            if (! $team->canBeDeleted()) {
                throw ValidationException::withMessages([
                    'competition_team' => 'Teams in ended seasons and teams with roster history cannot be deleted.',
                ]);
            }
        });
    }
}
