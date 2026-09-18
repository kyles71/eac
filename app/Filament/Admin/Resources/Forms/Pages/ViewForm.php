<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages;

use App\Filament\Admin\Resources\Forms\FormResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewForm extends ViewRecord
{
    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('analytics')
                ->icon('heroicon-o-chart-bar')
                ->url(fn (): string => FormResource::getUrl('analytics', ['record' => $this->getRecord()])),
            EditAction::make(),
        ];
    }
}
