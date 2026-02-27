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
    public ?Order $order = null;

    protected static ?string $title = 'Order Confirmation';

    protected static ?string $slug = 'checkout/success';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $sessionId = request()->query('session_id');
        $orderId = request()->query('order_id');

        if ($sessionId !== null) {
            $this->order = Order::query()
                ->where('user_id', auth()->id())
                ->where('stripe_checkout_session_id', $sessionId)
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
            Section::make('Order Details')
                ->schema([
                    TextEntry::make('order_number')
                        ->label('Order Number')
                        ->state(fn (): string => "#{$this->order->id}"),
                    TextEntry::make('status')
                        ->label('Status')
                        ->state(fn (): string => $this->order->status->getLabel())
                        ->badge()
                        ->color(fn (): string => match ($this->order->status) {
                            OrderStatus::Completed => 'success',
                            OrderStatus::Pending => 'warning',
                            OrderStatus::Failed => 'danger',
                            OrderStatus::Refunded => 'gray',
                        }),
                    TextEntry::make('total')
                        ->label('Total Paid')
                        ->state(fn (): string => $this->order->formattedTotal()),
                    TextEntry::make('date')
                        ->label('Date')
                        ->state(fn (): string => $this->order->created_at->format('M j, Y g:i A')),
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
