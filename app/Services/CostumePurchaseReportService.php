<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Costume;
use App\Models\Product;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class CostumePurchaseReportService
{
    public function __construct(
        private ProductablePurchaseReportService $purchaseReportService,
        private CostumePurchaseRequirementService $requirementService,
    ) {}

    public function downloadAllPurchases(): StreamedResponse
    {
        return $this->purchaseReportService->downloadAll(Costume::class, 'Costume', 'costume');
    }

    public function downloadPurchasesForCostume(Costume $costume): StreamedResponse
    {
        return $this->purchaseReportService->downloadForProductable($costume, Costume::class, 'Costume', 'costume');
    }

    public function downloadPurchasesForProduct(Product $product): StreamedResponse
    {
        return $this->purchaseReportService->downloadForProduct($product, Costume::class, 'Costume');
    }

    public function downloadRequirements(Costume $costume): StreamedResponse
    {
        $costume->loadMissing('product');

        if (! $costume->product instanceof Product) {
            throw new InvalidArgumentException('The costume does not have a Product listing.');
        }

        return $this->requirementDownload(
            $costume->product,
            "costume-{$costume->getKey()}-order-status-".now()->format('Y-m-d').'.csv',
        );
    }

    public function downloadRequirementsForProduct(Product $product): StreamedResponse
    {
        return $this->requirementDownload(
            $product,
            "product-{$product->getKey()}-costume-order-status-".now()->format('Y-m-d').'.csv',
        );
    }

    private function requirementDownload(Product $product, string $filename): StreamedResponse
    {
        $product->loadMissing('productable.course');

        if (! $product->productable instanceof Costume) {
            throw new InvalidArgumentException('Order status reports are only available for Costume Products.');
        }

        $costume = $product->productable;
        $rows = $this->requirementService->rowsForProduct($product);

        return response()->streamDownload(function () use ($costume, $product, $rows): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            $this->writeRow($output, [
                'Costume',
                'Course',
                'Program Type',
                'Product Listing',
                'Order Due Date',
                'Household Name',
                'Household Email',
                'Students / Enrollment Seats',
                'Required Quantity',
                'Purchased Quantity',
                'Remaining Quantity',
                'Status',
                'Order Numbers',
                'Most Recent Purchase',
            ]);

            foreach ($rows as $row) {
                $this->writeRow($output, [
                    $costume->name,
                    $costume->course->name,
                    $costume->course->program_type->value,
                    $product->name,
                    $product->order_due_on?->toDateString(),
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
