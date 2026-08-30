<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Store\RedeemGiftCard;
use App\Models\GiftCard;
use App\Models\User;
use App\Services\StoreCodeAttemptLimiter;
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
                /** @var User $user */
                $user = auth()->user();
                $attemptLimiter = app(StoreCodeAttemptLimiter::class);

                if ($attemptLimiter->hasTooManyAttempts($user)) {
                    Notification::make()
                        ->title('Too many code attempts')
                        ->body("Try again in {$attemptLimiter->secondsUntilAvailable($user)} seconds.")
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $action = new RedeemGiftCard;
                    $giftCard = $action->handle($data['code'], $user);

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
                    $attemptLimiter->recordFailure($user);

                    Notification::make()
                        ->title('Invalid gift card')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getDefaultName(): string
    {
        return 'redeemGiftCard';
    }

    public function afterSuccessfulRedemption(?Closure $callback): static
    {
        $this->afterSuccessfulRedemption = $callback;

        return $this;
    }
}
