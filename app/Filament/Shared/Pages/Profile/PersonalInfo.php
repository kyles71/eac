<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Profile;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo as BreezyPersonalInfo;

final class PersonalInfo extends BreezyPersonalInfo
{
    public array $only = ['first_name', 'last_name', 'email'];

    protected function getProfileFormSchema(): array
    {
        $groupFields = Group::make([
            TextInput::make('first_name')
                ->required()
                ->maxLength(255)
                ->autofocus(),
            TextInput::make('last_name')
                ->required()
                ->maxLength(255)
                ->autofocus(),
            $this->getEmailComponent(),
        ])->columnSpan(2);

        return ($this->hasAvatars)
            ? [filament('filament-breezy')->getAvatarUploadComponent(), $groupFields]
            : [$groupFields];
    }
}
