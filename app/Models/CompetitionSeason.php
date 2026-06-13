<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class CompetitionSeason extends Model
{
    /** @use HasFactory<\Database\Factories\CompetitionSeasonFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public static function comparisonDate(?CarbonInterface $date = null): string
    {
        return ($date ?? Carbon::now())
            ->copy()
            ->timezone((string) config('app.display_timezone', config('app.timezone')))
            ->toDateString();
    }

    public static function constrainToCurrent(Builder $query, ?CarbonInterface $date = null): Builder
    {
        $comparisonDate = self::comparisonDate($date);

        return $query
            ->whereDate('starts_on', '<=', $comparisonDate)
            ->whereDate('ends_on', '>=', $comparisonDate);
    }

    public static function constrainToNotEnded(Builder $query, ?CarbonInterface $date = null): Builder
    {
        return $query->whereDate('ends_on', '>=', self::comparisonDate($date));
    }

    public function januaryFirst(): CarbonInterface
    {
        $januaryFirst = $this->starts_on->copy()->startOfYear();

        if ($this->starts_on->isAfter($januaryFirst)) {
            $januaryFirst = $januaryFirst->addYear();
        }

        return $januaryFirst;
    }

    /** @return HasMany<CompetitionTeam, $this> */
    public function teams(): HasMany
    {
        return $this->hasMany(CompetitionTeam::class);
    }

    public function scopeCurrent(Builder $query, ?CarbonInterface $date = null): Builder
    {
        return self::constrainToCurrent($query, $date);
    }

    public function scopeNotEnded(Builder $query, ?CarbonInterface $date = null): Builder
    {
        return self::constrainToNotEnded($query, $date);
    }

    public function isCurrent(?CarbonInterface $date = null): bool
    {
        $comparisonDate = self::comparisonDate($date);

        return $this->starts_on->toDateString() <= $comparisonDate
            && $this->ends_on->toDateString() >= $comparisonDate;
    }

    public function isUpcoming(?CarbonInterface $date = null): bool
    {
        return $this->starts_on->toDateString() > self::comparisonDate($date);
    }

    public function hasEnded(?CarbonInterface $date = null): bool
    {
        return $this->ends_on->toDateString() < self::comparisonDate($date);
    }

    public function status(): string
    {
        if ($this->hasEnded()) {
            return 'Ended';
        }

        return $this->isCurrent() ? 'Current' : 'Upcoming';
    }

    public function hasRosterHistory(): bool
    {
        return $this->teams()
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('students')
                ->orWhereHas('staff'))
            ->exists();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->hasEnded() && ! $this->hasRosterHistory();
    }

    protected static function booted(): void
    {
        self::saving(function (CompetitionSeason $season): void {
            $season->validateDateRange();
            $season->validateNoOverlap();
        });

        self::updating(function (CompetitionSeason $season): void {
            if ($season->originalHasEnded()) {
                throw ValidationException::withMessages([
                    'competition_season' => 'Ended competition seasons cannot be changed.',
                ]);
            }
        });

        self::deleting(function (CompetitionSeason $season): void {
            if (! $season->canBeDeleted()) {
                throw ValidationException::withMessages([
                    'competition_season' => 'Ended competition seasons and seasons with roster history cannot be deleted.',
                ]);
            }
        });
    }

    private function validateDateRange(): void
    {
        if ($this->starts_on->lte($this->ends_on)) {
            return;
        }

        throw ValidationException::withMessages([
            'ends_on' => 'The end date must be on or after the start date.',
        ]);
    }

    private function validateNoOverlap(): void
    {
        $overlaps = self::query()
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->getKey()))
            ->whereDate('starts_on', '<=', $this->ends_on->toDateString())
            ->whereDate('ends_on', '>=', $this->starts_on->toDateString())
            ->exists();

        if (! $overlaps) {
            return;
        }

        throw ValidationException::withMessages([
            'starts_on' => 'Competition season dates cannot overlap another season.',
            'ends_on' => 'Competition season dates cannot overlap another season.',
        ]);
    }

    private function originalHasEnded(): bool
    {
        $originalEndsOn = $this->getRawOriginal('ends_on');

        return is_string($originalEndsOn)
            && $originalEndsOn < self::comparisonDate();
    }
}
