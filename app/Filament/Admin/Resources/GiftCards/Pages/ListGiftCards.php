<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCards\Pages;

use App\Filament\Admin\Resources\GiftCards\GiftCardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListGiftCards extends ListRecords
{
    protected static string $resource = GiftCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(function (array $data): array {
                    $initialAmount = (int) $data['initial_amount'];

                    return [
                        ...$data,
                        'remaining_amount' => $initialAmount,
                        'redeemed_by_user_id' => null,
                        'redeemed_at' => null,
                        'is_active' => true,
                    ];
                }),
        ];
    }
}
