<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Pages;

use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Filament\User\Resources\FormUsers\Schemas\FormUserInfolist;
use App\Models\FormAssignment;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

final class ViewFormUser extends ViewRecord
{
    protected static string $resource = FormUserResource::class;

    public function infolist(Schema $schema): Schema
    {
        return FormUserInfolist::configure($schema, $this->assignment());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('update')
                ->url(fn (): string => $this->getResource()::getUrl('revise', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => $this->assignment()->formCanBeUpdated()),
        ];
    }

    private function assignment(): FormAssignment
    {
        /** @var FormAssignment $assignment */
        $assignment = $this->getRecord();

        return $assignment;
    }
}
