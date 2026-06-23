<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\CreditGrantStatus;
use App\Models\CreditGrant;
use App\Models\User;
use App\Services\CreditLedgerService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class RevokeCreditGrantAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Revoke Unused Credit')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('revoke')
            ->visible(fn (CreditGrant $record): bool => $record->status() === CreditGrantStatus::Active)
            ->requiresConfirmation()
            ->modalDescription(fn (CreditGrant $record): string => format_money($record->remaining_amount).' of unused credit will become unavailable.')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (CreditGrant $record, array $data): void {
                app(CreditLedgerService::class)->revoke($record, $this->actor(), $data['reason']);

                Notification::make()
                    ->title('Store credit revoked')
                    ->success()
                    ->send();
            });
    }

    public static function getDefaultName(): string
    {
        return 'revokeCreditGrant';
    }

    private function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
