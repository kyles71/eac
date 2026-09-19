<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Gear;
use App\Models\Product;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class GearPurchaseReportService
{
    public function __construct(private ProductablePurchaseReportService $reportService) {}

    public function downloadAll(): StreamedResponse
    {
        return $this->reportService->downloadAll(Gear::class, 'Gear', 'gear');
    }

    public function downloadForGear(Gear $gear): StreamedResponse
    {
        return $this->reportService->downloadForProductable($gear, Gear::class, 'Gear', 'gear');
    }

    public function downloadForProduct(Product $product): StreamedResponse
    {
        return $this->reportService->downloadForProduct($product, Gear::class, 'Gear');
    }
}
