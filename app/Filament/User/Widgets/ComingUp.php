<?php

declare(strict_types=1);

namespace App\Filament\User\Widgets;

use App\Filament\Shared\Pages\Calendar as CalendarPage;
use App\Models\Calendar;
use App\Models\User;
use App\Services\DashboardScheduleService;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

final class ComingUp extends Widget
{
    protected string $view = 'filament.user.widgets.coming-up';

    protected int|string|array $columnSpan = 'full';

    public function getColumnSpan(): int|string|array
    {
        return NextPayment::canView() ? 1 : 'full';
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function events(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        $calendar = Calendar::query()
            ->visibleTo($user)
            ->where('slug', Calendar::SLUG_MY)
            ->first();

        if (! $calendar instanceof Calendar) {
            return collect();
        }

        return app(DashboardScheduleService::class)
            ->upcoming($user, $calendar, now(), now()->addDays(30))
            ->take(5)
            ->values();
    }

    public function calendarUrl(): string
    {
        return CalendarPage::getUrl();
    }
}
