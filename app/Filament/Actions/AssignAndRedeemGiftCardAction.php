<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Mail\QueueManagedEmail;
use App\Actions\Store\RedeemGiftCard;
use App\Models\GiftCard;
use App\Models\User;
use App\Services\Mail\GiftCardAssignedRedemptionContentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class AssignAndRedeemGiftCardAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Redeem')
            ->icon(Heroicon::OutlinedGift)
            ->authorize('redeem')
            ->visible(fn (GiftCard $record): bool => $record->isRedeemable())
            ->modalHeading(fn (GiftCard $record): string => 'Redeem '.$record->code)
            ->modalDescription(fn (GiftCard $record): string => $record->formattedRemainingAmount().' will be added to the selected user as store credit.')
            ->modalSubmitActionLabel('Redeem')
            ->schema([
                Select::make('recipient_id')
                    ->label('Recipient')
                    ->options(fn (): array => self::userOptions())
                    ->getSearchResultsUsing(fn (string $search): array => self::userOptions($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::userLabelForKey($value))
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->action(function (GiftCard $record, array $data): void {
                /** @var User $recipient */
                $recipient = User::query()->findOrFail((int) $data['recipient_id']);

                if (! filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
                    Notification::make()
                        ->title('Recipient email is invalid')
                        ->body('Choose a recipient with a valid email address before assigning this gift card.')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $redeemedGiftCard = app(RedeemGiftCard::class)->handle($record->code, $recipient);
                } catch (InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Gift card could not be redeemed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $payload = app(GiftCardAssignedRedemptionContentService::class)->for($redeemedGiftCard, $recipient);
                $queued = app(QueueManagedEmail::class)->handle(
                    recipients: $recipient->email,
                    emailTypeKey: 'gift-card-assigned-redemption',
                    tokens: $payload['tokens'],
                    slots: $payload['slots'],
                );

                $notification = Notification::make()
                    ->title($queued ? 'Gift card assigned and redeemed' : 'Gift card redeemed')
                    ->body($queued
                        ? "{$redeemedGiftCard->formattedInitialAmount()} was added to {$recipient->getFilamentName()} and the email was queued."
                        : "{$redeemedGiftCard->formattedInitialAmount()} was added to {$recipient->getFilamentName()}, but the assignment email is disabled in Mail Manager.");

                $queued ? $notification->success() : $notification->warning();

                $notification->send();
            });
    }

    public static function getDefaultName(): string
    {
        return 'assignAndRedeemGiftCard';
    }

    /**
     * @return array<int, string>
     */
    private static function userOptions(?string $search = null): array
    {
        $query = User::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('email');

        if (filled($search)) {
            str($search)
                ->squish()
                ->explode(' ')
                ->filter()
                ->each(function (string $term) use ($query): void {
                    $query->where(function (Builder $query) use ($term): void {
                        $query
                            ->whereLike('first_name', "%{$term}%")
                            ->orWhereLike('last_name', "%{$term}%")
                            ->orWhereLike('email', "%{$term}%");
                    });
                });
        }

        return $query
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => self::userLabel($user)])
            ->all();
    }

    private static function userLabelForKey(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $user = User::query()->find((int) $value);

        return $user instanceof User ? self::userLabel($user) : null;
    }

    private static function userLabel(User $user): string
    {
        $name = mb_trim("{$user->first_name} {$user->last_name}");

        return filled($user->email) ? "{$name} ({$user->email})" : $name;
    }
}
