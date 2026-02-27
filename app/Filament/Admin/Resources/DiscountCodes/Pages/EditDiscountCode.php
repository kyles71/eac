<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DiscountCodes\Pages;

use App\Filament\Admin\Resources\DiscountCodes\DiscountCodeResource;
use Filament\Resources\Pages\EditRecord;

final class EditDiscountCode extends EditRecord
{
    protected static string $resource = DiscountCodeResource::class;
}
