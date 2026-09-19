<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductType;
use App\Models\Product;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ProductPurchaseReportService
{
    public function __construct(private ProductPurchaseRequirementService $requirements) {}

    public function download(Product $product): StreamedResponse
    {
        if (! $product->is_purchase_required) {
            throw new InvalidArgumentException('Purchase status reports are only available for required Products.');
        }

        $product->loadMissing('productable');
        $rows = $this->requirements->rowsForProduct($product);
        $filename = "product-{$product->getKey()}-purchase-status-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($product, $rows): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            $this->writeRow($output, [
                'Product',
                'Product Type',
                'Linked Item',
                'Reminder Date',
                'Purchase Deadline',
                'Household Name',
                'Household Email',
                'Qualifying Students / Enrollment Seats',
                'Required Quantity',
                'Purchased Quantity',
                'Remaining Quantity',
                'Status',
                'Order Numbers',
                'Most Recent Purchase',
            ]);

            foreach ($rows as $row) {
                $this->writeRow($output, [
                    $product->name,
                    ProductType::labelForProductableType($product->productable_type),
                    $product->productable?->getAttribute('name'),
                    $product->purchase_reminder_on?->toDateString(),
                    $product->available_until?->toISOString(),
                    $row['user']->fullName,
                    $row['user']->email,
                    implode('; ', $row['targets']),
                    $row['required'],
                    $row['purchased'],
                    $row['remaining'],
                    $row['status']->value,
                    implode('; ', $row['order_numbers']),
                    $row['most_recent_purchase'],
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
