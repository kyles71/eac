<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PurchaseRequirementStatus;
use App\Models\Costume;
use App\Models\Product;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @phpstan-type CostumeNotOrderedRow array{
 *     costume_id: int,
 *     costume: string,
 *     vendor: ?string,
 *     vendor_number: ?string,
 *     costume_color: ?string,
 *     course: string,
 *     academic_term: string,
 *     product_id: int,
 *     product: string,
 *     household: string,
 *     household_email: string,
 *     targets: string,
 *     required: int,
 *     reminder_on: ?string,
 *     deadline: ?string,
 *     status: string
 * }
 */
final readonly class CostumePurchaseReportService
{
    /** @var array<string, string> */
    private const PURCHASE_REPORT_ATTRIBUTES = [
        'Vendor' => 'vendor',
        'Vendor Number' => 'vendor_number',
        'Costume Color' => 'costume_color',
    ];

    public function __construct(
        private ProductablePurchaseReportService $purchaseReportService,
        private ProductPurchaseReportService $requirementReportService,
        private ProductPurchaseRequirementService $requirements,
    ) {}

    public function downloadAllPurchases(): StreamedResponse
    {
        return $this->purchaseReportService->downloadAll(
            Costume::class,
            'Costume',
            'costume',
            self::PURCHASE_REPORT_ATTRIBUTES,
        );
    }

    public function downloadPurchasesForCostume(Costume $costume): StreamedResponse
    {
        return $this->purchaseReportService->downloadForProductable(
            $costume,
            Costume::class,
            'Costume',
            'costume',
            self::PURCHASE_REPORT_ATTRIBUTES,
        );
    }

    public function downloadPurchasesForProduct(Product $product): StreamedResponse
    {
        return $this->purchaseReportService->downloadForProduct(
            $product,
            Costume::class,
            'Costume',
            self::PURCHASE_REPORT_ATTRIBUTES,
        );
    }

    public function downloadRequirements(Costume $costume): StreamedResponse
    {
        $costume->loadMissing('product');

        if (! $costume->product instanceof Product) {
            throw new InvalidArgumentException('The costume does not have a Product listing.');
        }

        return $this->requirementReportService->download($costume->product);
    }

    public function downloadRequirementsForProduct(Product $product): StreamedResponse
    {
        return $this->requirementReportService->download($product);
    }

    /** @return Collection<string, CostumeNotOrderedRow> */
    public function notOrderedRows(): Collection
    {
        $costumes = Costume::query()
            ->with(['course.academicTerm', 'product'])
            ->whereHas('product', fn ($query) => $query
                ->where('is_active', true)
                ->where('is_purchase_required', true))
            ->orderBy('name')
            ->get();
        $rows = collect();

        foreach ($costumes as $costume) {
            $product = $costume->product;

            if (! $product instanceof Product) {
                continue;
            }

            foreach ($this->requirements->rowsForProduct($product) as $requirement) {
                if ($requirement['status'] !== PurchaseRequirementStatus::NotOrdered) {
                    continue;
                }

                $household = $requirement['user'];

                $rows->put("{$product->id}:{$household->id}", [
                    'costume_id' => $costume->id,
                    'costume' => $costume->name,
                    'vendor' => $costume->vendor,
                    'vendor_number' => $costume->vendor_number,
                    'costume_color' => $costume->costume_color,
                    'course' => $costume->course->name,
                    'academic_term' => $costume->course->academicTerm->display_name,
                    'product_id' => $product->id,
                    'product' => $product->name,
                    'household' => $household->fullName,
                    'household_email' => $household->email,
                    'targets' => implode(', ', $requirement['targets']),
                    'required' => $requirement['required'],
                    'reminder_on' => $product->purchase_reminder_on?->toDateString(),
                    'deadline' => $product->available_until?->toISOString(),
                    'status' => $requirement['status']->value,
                ]);
            }
        }

        return $rows->sortBy(fn (array $row): string => mb_strtolower($row['costume']."\0".$row['household']));
    }

    public function downloadNotOrdered(): StreamedResponse
    {
        $rows = $this->notOrderedRows();
        $filename = 'costume-products-not-ordered-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            $this->writeRow($output, [
                'Costume',
                'Vendor',
                'Vendor Number',
                'Costume Color',
                'Course',
                'Academic Term',
                'Product Listing',
                'Household Name',
                'Household Email',
                'Qualifying Students / Enrollment Seats',
                'Required Quantity',
                'Reminder Date',
                'Purchase Deadline',
                'Status',
            ]);

            foreach ($rows as $row) {
                $this->writeRow($output, [
                    $row['costume'],
                    $row['vendor'],
                    $row['vendor_number'],
                    $row['costume_color'],
                    $row['course'],
                    $row['academic_term'],
                    $row['product'],
                    $row['household'],
                    $row['household_email'],
                    $row['targets'],
                    $row['required'],
                    $row['reminder_on'],
                    $row['deadline'],
                    $row['status'],
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
