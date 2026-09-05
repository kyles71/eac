<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use App\Enums\ProductQuestionType;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Services\ProductQuestionAnswerService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;

final class ProductQuestionSchema
{
    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    public static function make(Product $product, int $quantity, string $statePath = 'question_answers'): array
    {
        $sections = self::sections($product, $quantity, $statePath);

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

    /**
     * @return array<Section>
     */
    public static function sections(Product $product, int $quantity, string $statePath): array
    {
        $product->loadMissing('questions');

        if ($product->questions->isEmpty()) {
            return [];
        }

        $sections = [];

        for ($unitNumber = 1; $unitNumber <= $quantity; $unitNumber++) {
            $sections[] = Section::make(self::unitLabel($product, $quantity, $unitNumber))
                ->description('Please answer the following questions for this item.')
                ->statePath("{$statePath}.{$unitNumber}")
                ->schema(
                    $product->questions
                        ->flatMap(fn (ProductQuestion $question): array => self::questionFields($question))
                        ->all(),
                );
        }

        return $sections;
    }

    private static function unitLabel(Product $product, int $quantity, int $unitNumber): string
    {
        if ($quantity === 1) {
            return $product->name;
        }

        return "{$product->name} — Item {$unitNumber} of {$quantity}";
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    private static function questionFields(ProductQuestion $question): array
    {
        $fieldName = ProductQuestionAnswerService::fieldName($question);

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
        $otherFieldName = ProductQuestionAnswerService::otherFieldName($question);

        return [
            Flex::make([
                Select::make($fieldName)
                    ->label($question->question)
                    ->options($options)
                    ->selectablePlaceholder(! $question->is_required)
                    ->required($question->is_required)
                    ->searchable(false)
                    ->afterStateUpdatedJs(fn (): ?string => $question->allows_other
                        ? <<<JS
                            if (\$state !== 'Other') {
                                \$set('{$otherFieldName}', null)
                            }
                            JS
                        : null),
                TextInput::make($otherFieldName)
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
