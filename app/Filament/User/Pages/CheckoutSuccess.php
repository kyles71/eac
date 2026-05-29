<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Enums\OrderStatus;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class CheckoutSuccess extends Page
{
    private const int MAX_STATUS_POLLS = 10;

    public ?Order $order = null;

    public ?string $redirectStatus = null;

    public int $statusPolls = 0;

    protected static ?string $title = 'Order Confirmation';

    protected static ?string $slug = 'checkout/success';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $paymentIntent = request()->query('payment_intent');
        $orderId = request()->query('order_id');
        $redirectStatus = request()->query('redirect_status');
        $this->redirectStatus = is_string($redirectStatus)
            ? $redirectStatus
            : null;

        if ($paymentIntent !== null) {
            $this->order = Order::query()
                ->where('user_id', auth()->id())
                ->where('stripe_payment_intent_id', $paymentIntent)
                ->with('orderItems.product')
                ->first();
        } elseif ($orderId !== null) {
            $this->order = Order::query()
                ->where('user_id', auth()->id())
                ->where('id', $orderId)
                ->with('orderItems.product')
                ->first();
        }
    }

    public function refreshOrderStatus(): void
    {
        if ($this->order === null || ! $this->isFinalizingPayment || $this->statusPolls >= self::MAX_STATUS_POLLS) {
            return;
        }

        $this->statusPolls++;

        $this->order = Order::query()
            ->where('user_id', auth()->id())
            ->where('id', $this->order->id)
            ->with('orderItems.product')
            ->first();

        unset($this->isFinalizingPayment, $this->hasExceededStatusPolling);
    }

    public function getIsFinalizingPaymentProperty(): bool
    {
        return $this->redirectStatus === 'succeeded'
            && $this->order?->status === OrderStatus::Processing;
    }

    public function getHasExceededStatusPollingProperty(): bool
    {
        return $this->isFinalizingPayment && $this->statusPolls >= self::MAX_STATUS_POLLS;
    }

    public function content(Schema $schema): Schema
    {
        if ($this->order === null) {
            return $schema
                ->components([
                    Section::make('Order Not Found')
                        ->schema([
                            TextEntry::make('message')
                                ->hiddenLabel()
                                ->state('We could not find your order. Please check your email for confirmation or contact support.'),
                        ]),
                ]);
        }

        $components = [
            Section::make('Payment Finalizing')
                ->schema([
                    TextEntry::make('payment_finalizing_message')
                        ->hiddenLabel()
                        ->state(fn (): string => $this->hasExceededStatusPolling
                            ? 'Your payment was submitted successfully and is taking a little longer than usual to finish confirming. This page is safe to refresh, and your order status will update as soon as confirmation is complete.'
                            : 'Your payment was submitted successfully. We are confirming the final details now, and this page will update automatically.'),
                ])
                ->visible(fn (): bool => $this->isFinalizingPayment)
                ->extraAttributes(['wire:poll.2s' => 'refreshOrderStatus']),
            Section::make('Order Details')
                ->schema([
                    TextEntry::make('order_number')
                        ->label('Order Number')
                        ->state(fn (): string => "#{$this->order->id}"),
                    TextEntry::make('status')
                        ->label('Status')
                        ->state($this->order->status)
                        ->badge(),
                    TextEntry::make('total')
                        ->label('Total Paid')
                        ->state(fn (): string => $this->order->formattedTotal()),
                    TextEntry::make('date')
                        ->label('Date')
                        ->state(fn () => $this->order->created_at)
                        ->dateTime('M j, Y g:i A'),
                ]),
            Section::make('Items Purchased')
                ->schema(
                    $this->order->orderItems->map(
                        function (\Illuminate\Database\Eloquent\Model $item): TextEntry {
                            /** @var \App\Models\OrderItem $item */
                            /** @var \App\Models\Product $product */
                            $product = $item->product;

                            return TextEntry::make("item_{$item->id}")
                                ->label($product->name)
                                ->state(fn (): string => "Qty: {$item->quantity} × {$item->formattedUnitPrice()} = {$item->formattedTotalPrice()}");
                        }
                    )->all()
                ),
        ];

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewEnrollments')
                ->label('View My Classes')
                ->icon(Heroicon::OutlinedAcademicCap)
                ->url(MyEnrollments::getUrl()),
            Action::make('continueShopping')
                ->label('Continue Shopping')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color('gray')
                ->url(Store::getUrl()),
        ];
    }
}
