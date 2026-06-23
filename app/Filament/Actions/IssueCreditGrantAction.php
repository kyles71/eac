<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\ProductType;
use App\Filament\Admin\Resources\CreditGrants\Schemas\CreditGrantForm;
use App\Models\CreditGrant;
use App\Models\User;
use App\Services\CreditLedgerService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class IssueCreditGrantAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Issue Store Credit')
            ->icon(Heroicon::OutlinedBanknotes)
            ->authorize(fn (): bool => $this->actor()->can('create', CreditGrant::class))
            ->modalHeading(fn (User $record): string => 'Issue store credit to '.$record->getFilamentName())
            ->schema(CreditGrantForm::components(includeRecipient: false))
            ->action(function (User $record, array $data): void {
                app(CreditLedgerService::class)->issue(
                    recipient: $record,
                    amount: (int) $data['initial_amount'],
                    description: $data['description'],
                    issuer: $this->actor(),
                    expiresOn: filled($data['expires_on'] ?? null)
                        ? CarbonImmutable::parse($data['expires_on'], 'America/New_York')
                        : null,
                    restrictedToProductType: filled($data['restricted_to_product_type'] ?? null)
                        ? ProductType::from($data['restricted_to_product_type'])
                        : null,
                    productIds: array_map('intval', $data['product_ids'] ?? []),
                );

                Notification::make()
                    ->title('Store credit issued')
                    ->success()
                    ->send();
            });
    }

    public static function getDefaultName(): string
    {
        return 'issueCreditGrant';
    }

    private function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
