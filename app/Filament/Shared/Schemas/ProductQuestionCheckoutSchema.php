<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use App\Enums\ProductQuestionType;
use App\Models\CartItem;
use App\Models\ProductQuestion;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;

final class ProductQuestionCheckoutSchema
{
    /** @param Collection<int, CartItem> $cartItems */
    public static function make(Collection $cartItems): array
    {
        $sections = [];

        /** @var CartItem $cartItem */
        foreach ($cartItems as $cartItem) {
            $questions = $cartItem->product->questions;

            if ($questions->isEmpty()) {
                continue;
            }

            for ($unitNumber = 1; $unitNumber <= $cartItem->quantity; $unitNumber++) {
                $sections[] = Section::make(self::unitLabel($cartItem, $unitNumber))
                    ->description('Please answer the following questions for this item.')
                    ->statePath("question_answers.{$cartItem->id}.{$unitNumber}")
                    ->schema(
                        $questions
                            ->flatMap(fn (ProductQuestion $question): array => self::questionFields($question))
                            ->all(),
                    );
            }
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

    private static function unitLabel(CartItem $cartItem, int $unitNumber): string
    {
        if ($cartItem->quantity === 1) {
            return $cartItem->product->name;
        }

        return "{$cartItem->product->name} — Item {$unitNumber} of {$cartItem->quantity}";
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    private static function questionFields(ProductQuestion $question): array
    {
        $fieldName = "question_{$question->id}";

        if ($question->type === ProductQuestionType::Text) {
            return [
                TextInput::make($fieldName)
                    ->label($question->question)
                    ->required($question->is_required)
                    ->maxLength($question->max_length),
            ];
        }

        $options = collect($question->options ?? [])
            ->mapWithKeys(fn (string $option): array => [$option => $option])
            ->when($question->allows_other, fn (Collection $options): Collection => $options->put('Other', 'Other'))
            ->all();

        return [
            Flex::make([
                Select::make($fieldName)
                    ->label($question->question)
                    ->options($options)
                    ->required($question->is_required)
                    ->searchable(false)
                    ->afterStateUpdatedJs(fn (): ?string => $question->allows_other
                        ? <<<JS
                            if (\$state !== 'Other') {
                                \$set('{$fieldName}_other', null)
                            }
                            JS
                        : null),
                TextInput::make("{$fieldName}_other")
                    ->label('Other Answer')
                    ->maxLength(255)
                    ->visibleJs(<<<JS
                        \$get('{$fieldName}') === 'Other'
                        JS)
                    ->required(fn (Get $get): bool => $question->is_required && $get($fieldName) === 'Other'),
            ]),
        ];
    }
}
