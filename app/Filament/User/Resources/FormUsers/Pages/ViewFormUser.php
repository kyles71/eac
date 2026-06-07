<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Pages;

use App\Enums\FormTypes;
use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Models\FormUser;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

final class ViewFormUser extends ViewRecord
{
    protected static string $resource = FormUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reviseWaiver')
                ->label('Update')
                ->url(fn (): string => FormUserResource::getUrl('revise', ['record' => $this->getRecord()]))
                ->visible(function (): bool {
                    /** @var FormUser $record */
                    $record = $this->getRecord();

                    return $record->form?->form_type === FormTypes::StudentWaiver
                        && $record->formCanBeUpdated();
                }),
        ];
    }
}
