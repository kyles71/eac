<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Models\Product;
use Filament\Forms\Components\TextInput;

final readonly class CustomGiftCardAmountField
{
    /**
     * @return array<TextInput>
     */
    public static function schema(Product $product): array
    {
        if (! $product->allowsCustomGiftCardAmount()) {
            return [];
        }

        $minimumAmount = $product->minimumCustomGiftCardAmount() ?? 100;

        return [
            TextInput::make('custom_gift_card_amount')
                ->label('Gift Card Amount')
                ->helperText('Whole dollars only. Minimum '.format_money($minimumAmount).'.')
                ->moneyCents($minimumAmount / 100)
                ->multipleOf(1)
                ->required()
                ->default($product->suggestedCustomGiftCardAmount()),
        ];
    }

    public static function amountFromActionData(Product $product, array $data): ?int
    {
        if (! $product->allowsCustomGiftCardAmount()) {
            return null;
        }

        $amount = $data['custom_gift_card_amount'] ?? null;

        return is_numeric($amount) ? (int) round((float) $amount) : null;
    }
}
