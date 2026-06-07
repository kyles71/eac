<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Pages;

use App\Actions\Forms\CreateStudentWaiverRevision;
use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Filament\User\Resources\FormUsers\Schemas\FormUserForm;
use App\Models\FormUser;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class ReviseFormUser extends EditRecord
{
    protected static string $resource = FormUserResource::class;

    public function form(Schema $schema): Schema
    {
        $this->source()->loadMissing('form');

        return FormUserForm::configure($schema, $this->source()->form->form_type, withRelationships: false);
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->authorizeAccess();

        $data = $this->form->getState();
        $responseData = $this->normalizeEmergencyContacts($data['responseable'] ?? []);

        try {
            $revision = $this->revisionAction()->handle(
                source: $this->source(),
                user: $this->authenticatedUser(),
                responseData: $responseData,
                signature: (string) $data['signature'],
                dateSigned: (string) $data['date_signed'],
            );
        } catch (InvalidArgumentException $exception) {
            $this->addError('data.signature', $exception->getMessage());

            return;
        }

        if ($shouldSendSavedNotification) {
            Notification::make()
                ->title('Medical waiver updated')
                ->success()
                ->send();
        }

        if ($shouldRedirect) {
            $this->redirect(FormUserResource::getUrl('view', ['record' => $revision]));
        }
    }

    public function getTitle(): string
    {
        return 'Update Medical Waiver';
    }

    protected function authorizeAccess(): void
    {
        abort_unless(
            $this->revisionAction()->canHandle($this->source(), $this->authenticatedUser()),
            403,
        );
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Save New Medical Waiver');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->revisionFormData();
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionFormData(): array
    {
        $source = $this->source()->loadMissing(['responseable.emergencyContacts', 'student']);
        $waiver = $this->revisionAction()->waiverForRevision($source, $this->authenticatedUser());

        $responseData = $waiver->attributesToArray();
        $responseData['userForm'] = ['student_id' => $source->student_id];
        $responseData['emergency_contacts'] = $waiver->emergencyContacts
            ->map(function ($emergencyContact): array {
                $relationship = $emergencyContact->relationship;

                return [
                    ...Arr::only($emergencyContact->attributesToArray(), [
                        'name',
                        'relationship',
                        'phone_number',
                        'wants_text_updates',
                        'email',
                    ]),
                    'relationship_option' => in_array($relationship, ['Mother', 'Father', 'Guardian'], true)
                        ? $relationship
                        : 'Other',
                ];
            })
            ->all();

        foreach ([
            'medical_release_signed_on',
            'health_safety_policy_signed_on',
            'media_release_signed_on',
        ] as $field) {
            $responseData[$field] = null;
        }

        return [
            'responseable' => $responseData,
            'signature' => null,
            'date_signed' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    private function normalizeEmergencyContacts(array $responseData): array
    {
        $responseData['emergency_contacts'] = collect($responseData['emergency_contacts'] ?? [])
            ->map(function (array $contact): array {
                if (($contact['relationship_option'] ?? null) !== 'Other') {
                    $contact['relationship'] = $contact['relationship_option'] ?? null;
                }

                unset($contact['relationship_option']);

                return $contact;
            })
            ->all();

        return $responseData;
    }

    private function revisionAction(): CreateStudentWaiverRevision
    {
        return app(CreateStudentWaiverRevision::class);
    }

    private function authenticatedUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function source(): FormUser
    {
        /** @var FormUser $source */
        $source = $this->getRecord();

        return $source;
    }
}
