<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DiscountCodes\Pages;

use App\Filament\Admin\Resources\DiscountCodes\DiscountCodeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateDiscountCode extends CreateRecord
{
    protected static string $resource = DiscountCodeResource::class;
}
