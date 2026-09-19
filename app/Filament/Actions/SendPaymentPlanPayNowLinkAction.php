<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Store\SendPaymentPlanPayNowLink;
use App\Models\PaymentPlan;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

final class SendPaymentPlanPayNowLinkAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Send Pay Now Link')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->authorize('sendPaymentLink')
            ->visible(fn (?PaymentPlan $record): bool => $record?->hasCollectibleMissedInstallments() ?? false)
            ->requiresConfirmation()
            ->modalHeading('Send Pay Now link?')
            ->modalDescription('The customer will receive a billing link and must sign in to select and pay missed installments.')
            ->stickyModalHeader(false)
            ->stickyModalFooter(false)
            ->action(function (Action $action, PaymentPlan $record): void {
                try {
                    if (! app(SendPaymentPlanPayNowLink::class)->handle($record)) {
                        throw new DomainException('The email could not be queued. Confirm the customer email and managed email type are available.');
                    }

                    Notification::make()
                        ->title('Pay Now link sent')
                        ->body('The customer email was queued.')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not send Pay Now link')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    public static function getDefaultName(): string
    {
        return 'sendPaymentPlanPayNowLink';
    }
}
