<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Pages;

use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Models\FormUser;
use App\Support\UserAttention;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use LogicException;

final class EditFormUser extends EditRecord
{
    protected static string $resource = FormUserResource::class;

    public function getTitle(): string
    {
        $record = $this->formUser()->loadMissing('form');
        $verb = $record->isCompleted() ? 'Update' : 'Complete';

        return "{$verb} {$record->form->name}";
    }

    public function form(Schema $schema): Schema
    {
        $record = $this->formUser();

        $record->loadMissing(['form']);

        return self::getResource()::form($schema, $record->form->form_type);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->formUser()->formCanBeUpdated()) {
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

    private function formUser(): FormUser
    {
        $record = $this->getRecord();

        if (! $record instanceof FormUser) {
            throw new LogicException('Form user edit pages require a form user record.');
        }

        return $record;
    }
}
