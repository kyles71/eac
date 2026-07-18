<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductQuestionType;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductQuestion;
use InvalidArgumentException;

final readonly class ProductQuestionAnswerService
{
    public static function fieldName(ProductQuestion $question): string
    {
        return "question_{$question->id}";
    }

    public static function otherFieldName(ProductQuestion $question): string
    {
        return self::fieldName($question).'_other';
    }

    /**
     * @param  array<int|string, mixed>  $submittedUnits
     * @return array<int, array<string, string|null>>
     */
    public function normalizeUnits(
        Product $product,
        array $submittedUnits,
        int $unitCount,
        int $firstUnitNumber = 1,
        ?int $totalQuantity = null,
    ): array {
        if ($unitCount < 1 || $firstUnitNumber < 1) {
            throw new InvalidArgumentException('Question answers must belong to at least one purchased unit.');
        }

        $product->loadMissing('questions');
        $totalQuantity ??= $firstUnitNumber + $unitCount - 1;
        $normalizedUnits = [];

        for ($offset = 0; $offset < $unitCount; $offset++) {
            $relativeUnitNumber = $offset + 1;
            $absoluteUnitNumber = $firstUnitNumber + $offset;
            $submittedAnswers = $submittedUnits[$relativeUnitNumber] ?? [];

            if (! is_array($submittedAnswers)) {
                throw new InvalidArgumentException('A purchaser question answer had an invalid format.');
            }

            $normalizedUnits[$absoluteUnitNumber] = $this->normalizeUnit(
                $product,
                $submittedAnswers,
                $absoluteUnitNumber,
                $totalQuantity,
            );
        }

        return $normalizedUnits;
    }

    /**
     * @param  array<int|string, mixed>  $submittedUnits
     * @return list<array<string, mixed>>
     */
    public function orderRows(CartItem $cartItem, array $submittedUnits): array
    {
        $product = $cartItem->product;
        $normalizedUnits = $this->normalizeUnits(
            $product,
            $submittedUnits,
            $cartItem->quantity,
            totalQuantity: $cartItem->quantity,
        );
        $rows = [];

        foreach ($normalizedUnits as $unitNumber => $normalizedAnswers) {
            /** @var ProductQuestion $question */
            foreach ($product->questions as $question) {
                $fieldName = self::fieldName($question);
                $selectedOption = null;
                $answer = null;

                if ($question->type === ProductQuestionType::Text) {
                    $answer = $normalizedAnswers[$fieldName] ?? null;
                } else {
                    $selectedOption = $normalizedAnswers[$fieldName] ?? null;

                    if ($selectedOption === 'Other') {
                        $answer = $normalizedAnswers[self::otherFieldName($question)] ?? 'Other';
                    }
                }

                $rows[] = [
                    'product_question_id' => $question->id,
                    'unit_number' => $unitNumber,
                    'question' => $question->question,
                    'question_type' => $question->type,
                    'was_required' => $question->is_required,
                    'question_order' => $question->sort_order,
                    'selected_option' => $selectedOption,
                    'answer' => $answer,
                ];
            }
        }

        return $rows;
    }

    /** @param array<int|string, mixed> $submittedUnits */
    public function isComplete(CartItem $cartItem, array $submittedUnits): bool
    {
        try {
            $this->normalizeUnits(
                $cartItem->product,
                $submittedUnits,
                $cartItem->quantity,
                totalQuantity: $cartItem->quantity,
            );

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @param  array<int|string, mixed>  $submittedAnswers
     * @return array<string, string|null>
     */
    private function normalizeUnit(
        Product $product,
        array $submittedAnswers,
        int $unitNumber,
        int $totalQuantity,
    ): array {
        $normalizedAnswers = [];

        /** @var ProductQuestion $question */
        foreach ($product->questions as $question) {
            $fieldName = self::fieldName($question);
            $submittedAnswer = $submittedAnswers[$fieldName] ?? null;

            if ($question->type === ProductQuestionType::Text) {
                $answer = $this->normalizeStringAnswer($submittedAnswer);

                if ($question->is_required && $answer === null) {
                    throw new InvalidArgumentException($this->requiredQuestionMessage($product, $question, $unitNumber, $totalQuantity));
                }

                if ($answer !== null && $question->max_length !== null && mb_strlen($answer) > $question->max_length) {
                    throw new InvalidArgumentException(
                        "Your answer to \"{$question->question}\" may not be longer than {$question->max_length} characters.",
                    );
                }

                $normalizedAnswers[$fieldName] = $answer;

                continue;
            }

            $selectedOption = $this->normalizeStringAnswer($submittedAnswer);

            if ($question->is_required && $selectedOption === null) {
                throw new InvalidArgumentException($this->requiredQuestionMessage($product, $question, $unitNumber, $totalQuantity));
            }

            if ($selectedOption === 'Other') {
                if (! $question->allows_other) {
                    throw new InvalidArgumentException("Other is not a valid answer to \"{$question->question}\".");
                }

                $otherAnswer = $this->normalizeStringAnswer($submittedAnswers[self::otherFieldName($question)] ?? null);

                if ($question->is_required && $otherAnswer === null) {
                    throw new InvalidArgumentException("Please specify the Other answer for \"{$question->question}\".");
                }

                if ($otherAnswer !== null && mb_strlen($otherAnswer) > 255) {
                    throw new InvalidArgumentException("The Other answer to \"{$question->question}\" may not be longer than 255 characters.");
                }

                $normalizedAnswers[self::otherFieldName($question)] = $otherAnswer;
            } elseif ($selectedOption !== null && ! in_array($selectedOption, $question->options ?? [], true)) {
                throw new InvalidArgumentException("The selected answer to \"{$question->question}\" is no longer available.");
            }

            $normalizedAnswers[$fieldName] = $selectedOption;
        }

        return $normalizedAnswers;
    }

    private function normalizeStringAnswer(mixed $answer): ?string
    {
        if ($answer === null) {
            return null;
        }

        if (! is_string($answer)) {
            throw new InvalidArgumentException('A purchaser question answer had an invalid format.');
        }

        $answer = mb_trim($answer);

        return $answer === '' ? null : $answer;
    }

    private function requiredQuestionMessage(
        Product $product,
        ProductQuestion $question,
        int $unitNumber,
        int $totalQuantity,
    ): string {
        $item = $totalQuantity === 1
            ? $product->name
            : "{$product->name} item {$unitNumber}";

        return "Please answer \"{$question->question}\" for {$item}.";
    }
}
