<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Costume;
use App\Models\Product;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class CostumePurchaseReportService
{
    public function __construct(
        private ProductablePurchaseReportService $purchaseReportService,
        private ProductPurchaseReportService $requirementReportService,
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

        return $this->requirementReportService->download($costume->product);
    }

    public function downloadRequirementsForProduct(Product $product): StreamedResponse
    {
        return $this->requirementReportService->download($product);
    }
}
