<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlans\Pages;

use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentPlans extends ListRecords
{
    protected static string $resource = PaymentPlanResource::class;
}
