<?php

namespace App\Filament\User\Pages\Auth;

use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class Register extends BaseRegister
{
    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->components([
                TextInput::make('first_name')
                    ->required()
                    ->maxLength(255)
                    ->autofocus(),
                TextInput::make('last_name')
                    ->required()
                    ->maxLength(255)
                    ->autofocus(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getTermsFormComponent(),
            ]);
    }

    protected function getTermsFormComponent(): Component
    {
        return Checkbox::make('terms')
            ->label('I agree to the terms and conditions')
            ->required()
            ->accepted()
            ->dehydrated(false);
    }

    protected function mutateFormDataBeforeRegister(array $data): array
    {
        unset($data['terms']);

        return $data;
    }

    protected function afterRegister(): void
    {
        // send welcome email, etc.
    }
}
