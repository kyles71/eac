<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\EventSubstituteCoverageStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

final class SubstituteCoverageReminder extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.admin.widgets.substitute-coverage-reminder';

    protected int|string|array $columnSpan = 'full';

    private ?int $cachedCoverageCount = null;

    public static function canView(): bool
    {
        return self::coverageQuery()->exists();
    }

    public function coverageCount(): int
    {
        return $this->cachedCoverageCount ??= self::coverageQuery()->count();
    }

    public function heading(): string
    {
        $count = $this->coverageCount();

        return $count.' Upcoming '.($count === 1 ? 'Class Needs' : 'Classes Need').' Substitute Coverage';
    }

    public function description(): string
    {
        $count = $this->coverageCount();

        return $count === 1
            ? 'This class is still marked as Needs Substitute or Awaiting Response.'
            : 'These classes are still marked as Needs Substitute or Awaiting Response.';
    }

    public function eventsUrl(): string
    {
        return EventResource::getUrl('index', [
            'filters' => [
                'substitute_coverage' => ['values' => [
                    EventSubstituteCoverageStatus::NeedsSubstitute->value,
                    EventSubstituteCoverageStatus::AwaitingResponse->value,
                ]],
            ],
        ], panel: 'admin');
    }

    /** @return Builder<Event> */
    private static function coverageQuery(): Builder
    {
        $user = auth()->user();
        $query = Event::query()->needsSubstituteAttention();

        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas(
            'course.teachers',
            fn (Builder $query): Builder => $query->whereKey($user->id),
        );
    }
}
