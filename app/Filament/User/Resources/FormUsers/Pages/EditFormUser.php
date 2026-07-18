<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Pages;

use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Filament\User\Resources\FormUsers\Schemas\FormUserForm;
use App\Models\FormAssignment;
use App\Support\UserAttention;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Kyle\FilamentFormBuilder\Actions\SaveFormResponseDraft;
use Kyle\FilamentFormBuilder\Actions\SubmitFormResponse;
use Kyle\FilamentFormBuilder\Enums\FormResponseStatus;
use Kyle\FilamentFormBuilder\Support\FormDefinition;

final class EditFormUser extends EditRecord
{
    protected static string $resource = FormUserResource::class;

    public function getTitle(): string
    {
        return $this->assignment()->form->name;
    }

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
        $response = $assignment->responses()
            ->where('form_version_id', $assignment->form_version_id)
            ->where('status', FormResponseStatus::Draft)
            ->latest('id')
            ->first()
            ?? $assignment->responses()
                ->where('form_version_id', $assignment->form_version_id)
                ->where('status', FormResponseStatus::Submitted)
                ->latest('submitted_at')
                ->latest('id')
                ->first();

        if ($response === null) {
            return [];
        }

        $state = $response->response_state;

        if ($response->status === FormResponseStatus::Submitted) {
            $today = now((string) config('app.display_timezone'))->toDateString();
            $state['signature'] = null;
            $state['date_signed'] = $today;

            foreach (app(FormDefinition::class)->fields($assignment->version->schema) as $field) {
                if (is_string($field['mapping']) && str_ends_with($field['mapping'], '_signed_on')) {
                    $state['answers'][$field['key']] = $today;
                }
            }
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
