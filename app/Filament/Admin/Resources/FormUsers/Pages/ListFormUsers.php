<?php

namespace App\Filament\Admin\Resources\FormUsers\Pages;

use App\Filament\Admin\Resources\FormUsers\FormUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFormUsers extends ListRecords
{
    protected static string $resource = FormUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
