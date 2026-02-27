<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Store\RedeemGiftCard as RedeemGiftCardAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use InvalidArgumentException;
use Livewire\Component;

final class RedeemGiftCard extends Component implements HasForms
{
    use InteractsWithForms;

    public string $code = '';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Gift Card Code')
                    ->required()
                    ->placeholder('Enter your gift card code'),
            ]);
    }

    public function redeem(): void
    {
        // @phpstan-ignore property.notFound
        $this->form->validate();

        try {
            $action = new RedeemGiftCardAction;
            $giftCard = $action->handle($this->code, auth()->user());

            $this->code = '';

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
    }

    public function render(): string
    {
        return <<<'HTML'
        <div>
            <form wire:submit="redeem" class="flex gap-3 items-end">
                {{ $this->form }}
                <button type="submit" class="fi-btn fi-btn-size-md fi-btn-color-primary rounded-lg px-4 py-2 text-sm font-semibold text-white bg-primary-600 hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400">
                    Redeem
                </button>
            </form>
        </div>
        HTML;
    }
}
