<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Actions\Store\IssueOrderRefundAction;
use App\Enums\OrderItemStatus;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Enrollment;
use App\Models\OrderItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Throwable;

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
                ->authorize('refund')
                ->visible(fn (): bool => in_array($record->status, [OrderStatus::Completed, OrderStatus::PartiallyRefunded], true)
                    && $record->refundableAmount() > 0)
                ->modalHeading('Refund Order')
                ->modalDescription(fn (): string => 'Refund up to '.$record->formattedRefundableAmount().'. Stripe refunds cannot be undone.')
                ->modalSubmitActionLabel('Issue refund')
                ->schema([
                    Grid::make()
                        ->schema([
                            TextInput::make('amount')
                                ->label('Refund amount')
                                ->moneyCents(0.01)
                                ->default(fn (): int => $record->refundableAmount())
                                ->maxValue(fn (): float => $record->refundableAmount() / 100)
                                ->required()
                                ->columnSpanFull()
                                ->live(onBlur: true),
                            Toggle::make('restore_store_credit')
                                ->label('Restore applied store credit')
                                ->helperText('Available only when all remaining Stripe funds are refunded and future installments are also cancelled.')
                                ->disabled(function (Get $get) use ($record): bool {
                                    $amountInCents = (int) round(((float) str_replace(',', '', (string) $get('amount'))) * 100);

                                    return $amountInCents !== $record->refundableAmount()
                                        || ($record->hasChargeableInstallments() && ! (bool) $get('cancel_remaining_installments'));
                                })
                                ->visible(fn (): bool => ($record->credit_applied + $record->restricted_credit_applied) > 0),
                            Toggle::make('cancel_remaining_installments')
                                ->label('Cancel remaining payment-plan installments')
                                ->helperText('Stops unpaid, failed, and overdue installments after the refund succeeds.')
                                ->live()
                                ->visible(fn (): bool => $record->hasChargeableInstallments()),
                            CheckboxList::make('enrollment_ids')
                                ->label('Unenroll dancers / remove purchased seats')
                                ->helperText('Optional. Selected enrollments are removed only after Stripe confirms the complete refund.')
                                ->options(fn (): array => self::enrollmentOptions($record))
                                ->columns(1)
                                ->columnSpanFull()
                                ->visible(fn (): bool => $record->orderItems()->whereHas('enrollments')->exists()),
                            Textarea::make('reason')
                                ->label('Internal reason')
                                ->helperText('Visible to administrators only. This is not sent to the customer or Stripe.')
                                ->maxLength(2000)
                                ->rows(3)
                                ->columnSpanFull()
                                ->required(),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data) use ($record): void {
                    $admin = auth()->user();

                    if (! $admin instanceof User) {
                        Notification::make()
                            ->title('Refund failed')
                            ->body('An authenticated administrator is required.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $refund = app(IssueOrderRefundAction::class)->handle(
                            order: $record,
                            processedBy: $admin,
                            amount: (int) $data['amount'],
                            reason: (string) $data['reason'],
                            enrollmentIds: array_map('intval', $data['enrollment_ids'] ?? []),
                            cancelRemainingInstallments: (bool) ($data['cancel_remaining_installments'] ?? false),
                            restoreStoreCredit: (bool) ($data['restore_store_credit'] ?? false),
                        );

                        Notification::make()
                            ->title(match ($refund->status) {
                                OrderRefundStatus::Succeeded => 'Refund completed',
                                OrderRefundStatus::Pending => 'Refund pending',
                                default => 'Refund requires attention',
                            })
                            ->body("Refund #{$refund->id} for {$refund->formattedAmount()} is {$refund->status->getLabel()}.")
                            ->color($refund->status->getColor())
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Refund failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }

                    $record->refresh();
                }),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function enrollmentOptions(\App\Models\Order $order): array
    {
        return $order->orderItems()
            ->with(['enrollments.course', 'enrollments.student'])
            ->get()
            ->flatMap(fn (OrderItem $orderItem) => $orderItem->enrollments)
            ->mapWithKeys(function (Enrollment $enrollment): array {
                $student = $enrollment->student_id === null
                    ? 'Unassigned seat'
                    : $enrollment->student->fullName;
                $course = $enrollment->course->name;

                return [$enrollment->id => "{$student} — {$course}"];
            })
            ->all();
    }
}
