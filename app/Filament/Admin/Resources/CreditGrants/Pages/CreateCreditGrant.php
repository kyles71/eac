<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CreditGrants\Pages;

use App\Enums\ProductType;
use App\Filament\Admin\Resources\CreditGrants\CreditGrantResource;
use App\Models\User;
use App\Services\CreditLedgerService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateCreditGrant extends CreateRecord
{
    protected static string $resource = CreditGrantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        /** @var User $recipient */
        $recipient = User::query()->findOrFail($data['user_id']);

        return app(CreditLedgerService::class)->issue(
            recipient: $recipient,
            amount: (int) $data['initial_amount'],
            description: $data['description'],
            issuer: $actor,
            expiresOn: filled($data['expires_on'] ?? null)
                ? CarbonImmutable::parse($data['expires_on'], 'America/New_York')
                : null,
            restrictedToProductType: filled($data['restricted_to_product_type'] ?? null)
                ? ProductType::from($data['restricted_to_product_type'])
                : null,
            productIds: array_map('intval', $data['product_ids'] ?? []),
        );
    }
}
