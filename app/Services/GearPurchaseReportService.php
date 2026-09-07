<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Gear;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductQuestionAnswer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class GearPurchaseReportService
{
    /**
     * @var list<string>
     */
    private const array FIXED_HEADERS = [
        'Order Number',
        'Purchase Date',
        'Purchaser Name',
        'Purchaser Email',
        'Gear',
        'Product Listing',
        'Unit Number',
        'Quantity',
        'Original Line Quantity',
        'Unit Price',
    ];

    public function downloadAll(): StreamedResponse
    {
        return $this->download(
            filename: 'gear-purchases-'.now()->format('Y-m-d').'.csv',
        );
    }

    public function downloadForGear(Gear $gear): StreamedResponse
    {
        return $this->download(
            filename: "gear-{$gear->getKey()}-purchases-".now()->format('Y-m-d').'.csv',
            gear: $gear,
        );
    }

    public function downloadForProduct(Product $product): StreamedResponse
    {
        if ($product->productable_type !== (new Gear)->getMorphClass()) {
            throw new InvalidArgumentException('Purchase reports are only available for Gear Products.');
        }

        return $this->download(
            filename: "product-{$product->getKey()}-purchases-".now()->format('Y-m-d').'.csv',
            product: $product,
        );
    }

    private function download(
        string $filename,
        ?Gear $gear = null,
        ?Product $product = null,
    ): StreamedResponse {
        $questionColumns = $this->questionColumns($gear, $product);

        return response()->streamDownload(function () use ($gear, $product, $questionColumns): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            $this->writeRow($output, [
                ...self::FIXED_HEADERS,
                ...array_values($questionColumns),
            ]);

            $orderItems = $this->orderItemQuery($gear, $product)
                ->with(['order.user', 'product.productable', 'questionAnswers'])
                ->lazyById(200);

            /** @var OrderItem $orderItem */
            foreach ($orderItems as $orderItem) {
                /** @var Gear $gearRecord */
                $gearRecord = $orderItem->product->productable;

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
                        $gearRecord->name,
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
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function questionColumns(?Gear $gear, ?Product $product): array
    {
        /** @var Collection<int, string> $questions */
        $questions = ProductQuestionAnswer::query()
            ->whereIn(
                'order_item_id',
                $this->orderItemQuery($gear, $product)->select('order_items.id'),
            )
            ->orderBy('question_order')
            ->orderBy('id')
            ->pluck('question')
            ->unique()
            ->values();

        $usedHeaders = collect(self::FIXED_HEADERS)
            ->mapWithKeys(fn (string $header): array => [mb_strtolower($header) => true])
            ->all();
        $columns = [];

        foreach ($questions as $question) {
            $baseHeader = isset($usedHeaders[mb_strtolower($question)])
                ? "Question: {$question}"
                : $question;
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

    /** @return Builder<OrderItem> */
    private function orderItemQuery(?Gear $gear, ?Product $product): Builder
    {
        $gearMorphClass = (new Gear)->getMorphClass();

        return OrderItem::query()
            ->whereHas(
                'order',
                fn (Builder $query): Builder => $query->where('status', OrderStatus::Completed->value),
            )
            ->whereHas('product', function (Builder $query) use ($gear, $gearMorphClass, $product): void {
                $query->where('productable_type', $gearMorphClass);

                if ($gear !== null) {
                    $query->where('productable_id', $gear->getKey());
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
        fputcsv(
            $output,
            array_map($this->formulaSafeValue(...), $values),
            ',',
            '"',
            '',
        );
    }

    private function formulaSafeValue(int|string|null $value): string
    {
        $value = (string) ($value ?? '');
        $firstMeaningfulCharacter = mb_ltrim($value, " \t\n\r\0\x0B")[0] ?? null;

        if (in_array($firstMeaningfulCharacter, ['=', '+', '-', '@'], true)) {
            return "'{$value}";
        }

        return $value;
    }
}
