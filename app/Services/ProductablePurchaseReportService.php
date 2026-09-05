<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductQuestionAnswer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ProductablePurchaseReportService
{
    /** @param class-string<Model> $productableClass */
    public function downloadAll(string $productableClass, string $subjectHeader, string $filenamePrefix): StreamedResponse
    {
        return $this->download(
            productableClass: $productableClass,
            subjectHeader: $subjectHeader,
            filename: "{$filenamePrefix}-purchases-".now()->format('Y-m-d').'.csv',
        );
    }

    /** @param class-string<Model> $productableClass */
    public function downloadForProductable(
        Model $productable,
        string $productableClass,
        string $subjectHeader,
        string $filenamePrefix,
    ): StreamedResponse {
        if (! $productable instanceof $productableClass) {
            throw new InvalidArgumentException("Purchase reports are only available for {$subjectHeader} records.");
        }

        return $this->download(
            productableClass: $productableClass,
            subjectHeader: $subjectHeader,
            filename: "{$filenamePrefix}-{$productable->getKey()}-purchases-".now()->format('Y-m-d').'.csv',
            productable: $productable,
        );
    }

    /** @param class-string<Model> $productableClass */
    public function downloadForProduct(Product $product, string $productableClass, string $subjectHeader): StreamedResponse
    {
        $morphClass = (new $productableClass)->getMorphClass();

        if ($product->productable_type !== $morphClass) {
            throw new InvalidArgumentException("Purchase reports are only available for {$subjectHeader} Products.");
        }

        return $this->download(
            productableClass: $productableClass,
            subjectHeader: $subjectHeader,
            filename: "product-{$product->getKey()}-purchases-".now()->format('Y-m-d').'.csv',
            product: $product,
        );
    }

    /** @param class-string<Model> $productableClass */
    private function download(
        string $productableClass,
        string $subjectHeader,
        string $filename,
        ?Model $productable = null,
        ?Product $product = null,
    ): StreamedResponse {
        $fixedHeaders = $this->fixedHeaders($subjectHeader);
        $questionColumns = $this->questionColumns($productableClass, $fixedHeaders, $productable, $product);

        return response()->streamDownload(function () use ($fixedHeaders, $productableClass, $productable, $product, $questionColumns): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            $this->writeRow($output, [...$fixedHeaders, ...array_values($questionColumns)]);

            $orderItems = $this->orderItemQuery($productableClass, $productable, $product)
                ->with(['order.user', 'product.productable', 'questionAnswers'])
                ->lazyById(200);

            /** @var OrderItem $orderItem */
            foreach ($orderItems as $orderItem) {
                /** @var Model $subject */
                $subject = $orderItem->product->productable;

                for ($unitNumber = 1; $unitNumber <= $orderItem->quantity; $unitNumber++) {
                    $answers = $orderItem->questionAnswers
                        ->where('unit_number', $unitNumber)
                        ->mapWithKeys(fn (ProductQuestionAnswer $answer): array => [
                            $answer->question => $answer->formattedAnswer(),
                        ]);

                    $this->writeRow($output, [
                        $orderItem->order->id,
                        $orderItem->order->created_at
                            ?->setTimezone((string) config('app.display_timezone', config('app.timezone')))
                            ->format('Y-m-d H:i:s'),
                        $orderItem->order->user->fullName,
                        $orderItem->order->user->email,
                        (string) $subject->getAttribute('name'),
                        $orderItem->product_name ?? $orderItem->product->name,
                        $unitNumber,
                        1,
                        $orderItem->quantity,
                        number_format($orderItem->unit_price / 100, 2, '.', ''),
                        ...collect(array_keys($questionColumns))
                            ->map(fn (string $question): ?string => $answers->get($question))
                            ->all(),
                    ]);
                }
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return list<string> */
    private function fixedHeaders(string $subjectHeader): array
    {
        return [
            'Order Number',
            'Purchase Date',
            'Purchaser Name',
            'Purchaser Email',
            $subjectHeader,
            'Product Listing',
            'Unit Number',
            'Quantity',
            'Original Line Quantity',
            'Unit Price',
        ];
    }

    /**
     * @param  class-string<Model>  $productableClass
     * @param  list<string>  $fixedHeaders
     * @return array<string, string>
     */
    private function questionColumns(
        string $productableClass,
        array $fixedHeaders,
        ?Model $productable,
        ?Product $product,
    ): array {
        /** @var Collection<int, string> $questions */
        $questions = ProductQuestionAnswer::query()
            ->whereIn('order_item_id', $this->orderItemQuery($productableClass, $productable, $product)->select('order_items.id'))
            ->orderBy('question_order')
            ->orderBy('id')
            ->pluck('question')
            ->unique()
            ->values();

        $usedHeaders = collect($fixedHeaders)
            ->mapWithKeys(fn (string $header): array => [mb_strtolower($header) => true])
            ->all();
        $columns = [];

        foreach ($questions as $question) {
            $baseHeader = isset($usedHeaders[mb_strtolower($question)]) ? "Question: {$question}" : $question;
            $header = $baseHeader;
            $suffix = 2;

            while (isset($usedHeaders[mb_strtolower($header)])) {
                $header = "{$baseHeader} ({$suffix})";
                $suffix++;
            }

            $columns[$question] = $header;
            $usedHeaders[mb_strtolower($header)] = true;
        }

        return $columns;
    }

    /**
     * @param  class-string<Model>  $productableClass
     * @return Builder<OrderItem>
     */
    private function orderItemQuery(string $productableClass, ?Model $productable, ?Product $product): Builder
    {
        $morphClass = (new $productableClass)->getMorphClass();

        return OrderItem::query()
            ->whereHas('order', fn (Builder $query): Builder => $query->where('status', OrderStatus::Completed->value))
            ->whereHas('product', function (Builder $query) use ($morphClass, $productable, $product): void {
                $query->where('productable_type', $morphClass);

                if ($productable !== null) {
                    $query->where('productable_id', $productable->getKey());
                }

                if ($product !== null) {
                    $query->whereKey($product->getKey());
                }
            });
    }

    /**
     * @param  resource  $output
     * @param  list<int|string|null>  $values
     */
    private function writeRow($output, array $values): void
    {
        fputcsv($output, array_map($this->formulaSafeValue(...), $values), ',', '"', '');
    }

    private function formulaSafeValue(int|string|null $value): string
    {
        $value = (string) ($value ?? '');
        $firstMeaningfulCharacter = mb_ltrim($value, " \t\n\r\0\x0B")[0] ?? null;

        return in_array($firstMeaningfulCharacter, ['=', '+', '-', '@'], true) ? "'{$value}" : $value;
    }
}
