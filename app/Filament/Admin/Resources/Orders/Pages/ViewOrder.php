<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\OrderItem;
use App\Services\StripeService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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
            Action::make('markFulfilled')
                ->label('Mark Items Fulfilled')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (): bool => $record->status === OrderStatus::Completed
                    && $record->orderItems()->where('status', OrderItemStatus::Pending)->exists())
                ->form([
                    CheckboxList::make('order_item_ids')
                        ->label('Select items to mark as fulfilled')
                        ->options(function () use ($record): array {
                            /** @var \Illuminate\Database\Eloquent\Collection<int, OrderItem> $items */
                            $items = $record->orderItems()
                                ->where('status', OrderItemStatus::Pending)
                                ->with('product')
                                ->get();

                            return $items
                                ->mapWithKeys(fn (OrderItem $item): array => [
                                    $item->id => "{$item->product->name} (x{$item->quantity})",
                                ])
                                ->all();
                        })
                        ->required(),
                ])
                ->action(function (array $data) use ($record): void {
                    $items = $record->orderItems()
                        ->whereIn('id', $data['order_item_ids'])
                        ->where('status', OrderItemStatus::Pending)
                        ->get();

                    /** @var OrderItem $item */
                    foreach ($items as $item) {
                        $item->markFulfilled();
                    }

                    Notification::make()
                        ->title('Items marked as fulfilled')
                        ->body($items->count().' item(s) marked as fulfilled.')
                        ->success()
                        ->send();
                }),
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
