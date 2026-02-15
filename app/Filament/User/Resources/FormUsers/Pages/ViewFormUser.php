<?php

namespace App\Filament\User\Resources\FormUsers\Pages;

use App\Filament\User\Resources\FormUsers\FormUserResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFormUser extends ViewRecord
{
    protected static string $resource = FormUserResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
