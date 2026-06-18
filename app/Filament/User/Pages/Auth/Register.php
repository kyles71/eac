<?php

declare(strict_types=1);

namespace App\Filament\User\Pages\Auth;

use App\Support\LegalDocuments\PortalTerms;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

final class Register extends BaseRegister
{
    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->components([
                Text::make('Welcome to the EAC Plié Portal! Please create your account using the parent/guardian\'s name and email address. After your account is created, you\'ll have a designated place to add your dancer\'s information.')
                    ->columnSpanFull(),
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
        $portalTerms = PortalTerms::document();
        $termsVersion = $portalTerms?->currentVersion();

        return Checkbox::make('terms')
            ->label('I agree to the terms and conditions')
            ->required()
            ->accepted()
            ->dehydrated(false)
            ->visible($portalTerms !== null)
            ->helperText($termsVersion === null ? null : new HtmlString(
                '<a class="fi-link fi-size-sm fi-color fi-color-primary fi-text-color-600 dark:fi-text-color-400" href="'.e(route('legal-documents.versions.show', $termsVersion)).'" target="_blank" rel="noopener noreferrer">View and print the terms and conditions</a>'
            ));
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
