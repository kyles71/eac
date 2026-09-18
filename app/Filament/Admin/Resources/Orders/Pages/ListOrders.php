<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Filament\Admin\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fulfillment')
                ->label('Order Fulfillment')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->url(OrderResource::getUrl('fulfillment'))
                ->visible(fn (): bool => auth()->user()?->can('Fulfill:Order') ?? false),
        ];
    }
}
