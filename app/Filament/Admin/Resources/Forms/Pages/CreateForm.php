<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages;

use App\Filament\Admin\Resources\Forms\FormResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateForm extends CreateRecord
{
    protected static string $resource = FormResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
