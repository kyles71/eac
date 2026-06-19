<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Pages;

use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Support\UserAttention;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

final class EditFormUser extends EditRecord
{
    protected static string $resource = FormUserResource::class;

    public function form(Schema $schema): Schema
    {
        $record = $this->getRecord();

        $record->loadMissing(['form']);

        return self::getResource()::form($schema, $record->form->form_type);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->getRecord()->formCanBeUpdated()) {
            $data['signature'] = null;
            $data['date_signed'] = null;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterSave(): void
    {
        $this->dispatch(UserAttention::UPDATED_EVENT);
        $this->dispatch('refresh-sidebar');
    }
}
