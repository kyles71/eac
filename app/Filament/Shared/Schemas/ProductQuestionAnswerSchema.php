<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use App\Models\OrderItem;
use App\Models\ProductQuestionAnswer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

final class ProductQuestionAnswerSchema
{
    /** @return list<Section> */
    public static function forOrderItem(OrderItem $orderItem, string $keyPrefix): array
    {
        $orderItem->loadMissing('questionAnswers');

        return $orderItem->questionAnswers
            ->groupBy('unit_number')
            ->map(function ($answers, int $unitNumber) use ($orderItem, $keyPrefix): Section {
                $heading = $orderItem->quantity === 1
                    ? "Answers for {$orderItem->product->name}"
                    : "{$orderItem->product->name} — Item {$unitNumber} of {$orderItem->quantity}";

                return Section::make($heading)
                    ->compact()
                    ->schema(
                        $answers
                            ->map(fn (ProductQuestionAnswer $answer): TextEntry => TextEntry::make("{$keyPrefix}_answer_{$answer->id}")
                                ->label($answer->question)
                                ->state($answer->formattedAnswer()))
                            ->all(),
                    );
            })
            ->values()
            ->all();
    }
}
