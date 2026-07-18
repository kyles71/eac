<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use App\Models\CartItem;
use Filament\Schemas\Components\Grid;
use Illuminate\Support\Collection;

final class ProductQuestionCheckoutSchema
{
    /** @param Collection<int, CartItem> $cartItems */
    public static function make(Collection $cartItems): array
    {
        $sections = [];

        /** @var CartItem $cartItem */
        foreach ($cartItems as $cartItem) {
            if ($cartItem->product->asksPurchaserQuestionsWhenAddingToCart()) {
                continue;
            }

            $sections = [
                ...$sections,
                ...ProductQuestionSchema::sections(
                    $cartItem->product,
                    $cartItem->quantity,
                    "question_answers.{$cartItem->id}",
                ),
            ];
        }

        if ($sections === []) {
            return [];
        }

        return [
            Grid::make([
                'default' => 1,
                'lg' => 2,
            ])
                ->schema($sections)
                ->columnSpanFull(),
        ];
    }
}
