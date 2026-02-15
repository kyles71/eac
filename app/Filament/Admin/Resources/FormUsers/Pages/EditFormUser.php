<?php

namespace App\Filament\Admin\Resources\FormUsers\Pages;

use App\Filament\Admin\Resources\FormUsers\FormUserResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFormUser extends EditRecord
{
    protected static string $resource = FormUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
