<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlanTemplates\Pages;

use App\Filament\Admin\Resources\PaymentPlanTemplates\PaymentPlanTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentPlanTemplates extends ListRecords
{
    protected static string $resource = PaymentPlanTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
