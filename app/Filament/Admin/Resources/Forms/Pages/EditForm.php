<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages;

use App\Filament\Admin\Resources\Forms\FormResource;
use Filament\Resources\Pages\EditRecord;

final class EditForm extends EditRecord
{
    protected static string $resource = FormResource::class;
}
