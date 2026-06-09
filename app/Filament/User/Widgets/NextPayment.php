<?php

declare(strict_types=1);

namespace App\Filament\User\Widgets;

use App\Filament\User\Pages\Billing;
use App\Models\Installment;
use App\Models\User;
use App\Services\DashboardAccountSummaryService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class NextPayment extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected int|array|null $columns = 1;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(DashboardAccountSummaryService::class)->nextInstallmentFor($user) instanceof Installment;
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $nextInstallment = app(DashboardAccountSummaryService::class)->nextInstallmentFor($user);

        if (! $nextInstallment instanceof Installment) {
            return [];
        }

        return [
            Stat::make('Next Payment', format_money($nextInstallment->amount))
                ->description('Due '.$nextInstallment->due_date->format('M j, Y'))
                ->descriptionIcon(Heroicon::OutlinedCreditCard)
                ->url(Billing::getUrl(['tab' => 'payment-plans'])),
        ];
    }
}
