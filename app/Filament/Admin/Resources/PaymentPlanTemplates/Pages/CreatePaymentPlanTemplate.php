<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlanTemplates\Pages;

use App\Filament\Admin\Resources\PaymentPlanTemplates\PaymentPlanTemplateResource;
use Filament\Resources\Pages\CreateRecord;

final class CreatePaymentPlanTemplate extends CreateRecord
{
    protected static string $resource = PaymentPlanTemplateResource::class;
}
