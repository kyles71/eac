<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonStatus;
use App\Filament\Admin\Resources\RecurringPrivateLessons\RecurringPrivateLessonResource;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class RecurringPrivateLessonAttention extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasAnyRole(['owner', 'super_admin'])
            && RecurringPrivateLessonCharge::query()
                ->where('status', RecurringPrivateLessonChargeStatus::Scheduled)
                ->whereHas('recurringPrivateLesson', fn ($query) => $query
                    ->where('status', RecurringPrivateLessonStatus::Active))
                ->exists();
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $scheduledCount = RecurringPrivateLessonCharge::query()
            ->where('status', RecurringPrivateLessonChargeStatus::Scheduled)
            ->whereHas('recurringPrivateLesson', fn ($query) => $query
                ->where('status', RecurringPrivateLessonStatus::Active))
            ->count();
        $soonestMonth = RecurringPrivateLessonCharge::query()
            ->join(
                'recurring_private_lesson_billing_periods as billing_periods',
                'billing_periods.id',
                '=',
                'recurring_private_lesson_charges.recurring_private_lesson_billing_period_id',
            )
            ->where('recurring_private_lesson_charges.status', RecurringPrivateLessonChargeStatus::Scheduled)
            ->whereHas('recurringPrivateLesson', fn ($query) => $query
                ->where('status', RecurringPrivateLessonStatus::Active))
            ->selectRaw('billing_periods.period_start as billing_month, COUNT(*) as scheduled_count')
            ->groupBy('billing_periods.period_start')
            ->orderBy('billing_periods.period_start')
            ->first();
        $soonestMonthCount = (int) ($soonestMonth?->getAttribute('scheduled_count') ?? 0);
        $soonestMonthName = filled($soonestMonth?->getAttribute('billing_month'))
            ? CarbonImmutable::parse((string) $soonestMonth->getAttribute('billing_month'))->format('F')
            : null;

        return [
            Stat::make('Scheduled private lessons', "{$scheduledCount} total".($soonestMonthName === null ? null : ", {$soonestMonthCount} in {$soonestMonthName}"))
                ->description('Lessons awaiting billing')
                ->color($scheduledCount > 0 ? 'warning' : 'success')
                ->url(RecurringPrivateLessonResource::getUrl()),
        ];
    }
}
