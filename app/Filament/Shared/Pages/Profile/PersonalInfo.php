<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Profile;

use App\Support\MediaDisks;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo as BreezyPersonalInfo;

final class PersonalInfo extends BreezyPersonalInfo
{
    public array $only = ['first_name', 'last_name', 'email'];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->getProfileFormSchema())
            ->columns(3)
            ->model($this->user)
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = collect($this->form->getState())->only($this->only)->all();
        $this->user->update($data);

        $this->form->model($this->user)->saveRelationships();

        $this->sendNotification();

        // this causes the panel switcher to duplicate
        $this->dispatch('refresh-topbar');
    }

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

        $avatarField = SpatieMediaLibraryFileUpload::make('media')
            ->collection('avatars')
            ->disk(MediaDisks::public())
            ->visibility('public')
            ->hiddenLabel(true)
            ->image()
            ->avatar();

        return [$avatarField, $groupFields];
    }
}
