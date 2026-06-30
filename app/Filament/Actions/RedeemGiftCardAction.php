<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Store\RedeemGiftCard;
use App\Models\GiftCard;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

final class RedeemGiftCardAction extends Action
{
    protected ?Closure $afterSuccessfulRedemption = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Redeem Gift Card')
            ->icon(Heroicon::OutlinedGift)
            ->schema(fn (): array => [
                TextInput::make('code')
                    ->label('Gift Card Code')
                    ->required()
                    ->placeholder('Enter your gift card code'),
            ])
            ->action(function (array $data): void {
                try {
                    $action = new RedeemGiftCard;
                    $giftCard = $action->handle($data['code'], auth()->user());

                    $this->evaluate(
                        $this->afterSuccessfulRedemption,
                        namedInjections: ['giftCard' => $giftCard],
                        typedInjections: [GiftCard::class => $giftCard],
                    );

                    Notification::make()
                        ->title('Gift card redeemed!')
                        ->body("Added {$giftCard->formattedInitialAmount()} to your store credit.")
                        ->success()
                        ->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Invalid gift card')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'redeemGiftCard';
    }

    public function afterSuccessfulRedemption(?Closure $callback): static
    {
        $this->afterSuccessfulRedemption = $callback;

        return $this;
    }
}
