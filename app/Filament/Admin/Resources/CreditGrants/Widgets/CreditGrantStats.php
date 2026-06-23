<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CreditGrants\Widgets;

use App\Enums\CreditTransactionType;
use App\Models\CreditGrant;
use App\Models\CreditTransaction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class CreditGrantStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $today = now('America/New_York')->toDateString();
        $netUsed = max(0, -(int) CreditTransaction::query()
            ->whereNotNull('credit_grant_id')
            ->whereIn('type', [CreditTransactionType::CheckoutDebit, CreditTransactionType::Refund])
            ->sum('amount'));

        return [
            Stat::make('Total Issued', format_money((int) CreditGrant::query()->sum('initial_amount'))),
            Stat::make('Admin Issued', format_money((int) CreditGrant::query()->whereNotNull('granted_by_user_id')->sum('initial_amount'))),
            Stat::make('Net Used', format_money($netUsed)),
            Stat::make('Available', format_money((int) CreditGrant::query()->available()->sum('remaining_amount'))),
            Stat::make('Expired Unused', format_money((int) CreditGrant::query()
                ->whereNull('revoked_at')
                ->whereDate('expires_on', '<', $today)
                ->sum('remaining_amount'))),
            Stat::make('Revoked Unused', format_money((int) CreditGrant::query()
                ->whereNotNull('revoked_at')
                ->sum('remaining_amount'))),
        ];
    }
}
