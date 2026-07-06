<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Pages;

use App\Actions\Forms\SaveFormResponseDraft;
use App\Actions\Forms\SubmitFormResponse;
use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Filament\User\Resources\FormUsers\Schemas\FormUserForm;
use App\Models\FormAssignment;
use App\Support\UserAttention;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

final class EditFormUser extends EditRecord
{
    protected static string $resource = FormUserResource::class;

    public function form(Schema $schema): Schema
    {
        return FormUserForm::configure($schema, $this->assignment());
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->authorizeAccess();

        $response = app(SubmitFormResponse::class)->handle($this->assignment(), $this->form->getState());

        if ($shouldSendSavedNotification) {
            Notification::make()
                ->title('Form submitted')
                ->success()
                ->send();
        }

        $this->dispatch(UserAttention::UPDATED_EVENT);
        $this->dispatch('refresh-sidebar');

        if ($shouldRedirect) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $response->assignment]));
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $assignment = $this->assignment();
        $response = $assignment->draftResponse()->first() ?? $assignment->latestSubmittedResponse()->first();

        if ($response === null) {
            return [];
        }

        $state = $response->response_state;

        if ($assignment->isCompleted()) {
            $state['signature'] = null;
            $state['date_signed'] = null;
        }

        return $state;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveDraft')
                ->label('Save Draft')
                ->action(function (): void {
                    app(SaveFormResponseDraft::class)->handle($this->assignment(), $this->form->getRawState());
                    Notification::make()->title('Draft saved')->success()->send();
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    private function assignment(): FormAssignment
    {
        /** @var FormAssignment $assignment */
        $assignment = $this->getRecord();

        return $assignment;
    }
}
