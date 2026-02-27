<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Services\StripeService;
use Exception;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        /** @var \App\Models\Order $record */
        $record = $this->getRecord();

        return [
            Action::make('refund')
                ->label('Refund')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->visible(fn (): bool => $record->status === OrderStatus::Completed && $record->stripe_payment_intent_id !== null)
                ->requiresConfirmation()
                ->modalHeading('Refund Order')
                ->modalDescription('Are you sure you want to refund this order? This action cannot be undone.')
                ->action(function () use ($record): void {
                    $stripeService = app(StripeService::class);

                    try {
                        $stripeService->refundPaymentIntent($record->stripe_payment_intent_id);

                        $record->update(['status' => OrderStatus::Refunded]);

                        Notification::make()
                            ->title('Order refunded')
                            ->body("Order #{$record->id} has been refunded successfully.")
                            ->success()
                            ->send();
                    } catch (Exception $e) {
                        Notification::make()
                            ->title('Refund failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
