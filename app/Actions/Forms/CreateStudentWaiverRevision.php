<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Enums\FormTypes;
use App\Models\FormUser;
use App\Models\StudentWaiver;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class CreateStudentWaiverRevision
{
    /**
     * @param  array<string, mixed>  $responseData
     */
    public function handle(FormUser $source, User $user, array $responseData, string $signature, string $dateSigned): FormUser
    {
        $source->loadMissing(['form', 'student', 'responseable']);

        $this->validateRevisionSource($source, $user);

        if (blank($signature) || blank($dateSigned)) {
            throw new InvalidArgumentException('A signature and signed date are required.');
        }

        return DB::transaction(function () use ($source, $user, $responseData, $signature, $dateSigned): FormUser {
            /** @var FormUser $lockedSource */
            $lockedSource = FormUser::query()
                ->with(['form', 'student', 'responseable'])
                ->lockForUpdate()
                ->findOrFail($source->getKey());

            $this->validateRevisionSource($lockedSource, $user);

            $emergencyContacts = $responseData['emergency_contacts'] ?? [];
            unset($responseData['emergency_contacts'], $responseData['userForm']);

            $waiver = StudentWaiver::query()->create(Arr::only($responseData, [
                'student_home_address',
                'signer_relationship',
                'allergies',
                'medical_conditions',
                'past_injuries',
                'medications',
                'medical_release_consent',
                'behavioral_notes',
                'medical_release_signed_on',
                'health_safety_policy_consent',
                'health_safety_policy_signed_on',
                'media_release_consent',
                'media_release_signed_on',
            ]));

            foreach ($emergencyContacts as $emergencyContact) {
                $waiver->emergencyContacts()->create(Arr::only($emergencyContact, [
                    'name',
                    'relationship',
                    'phone_number',
                    'wants_text_updates',
                    'email',
                ]));
            }

            $revision = new FormUser([
                'form_id' => $lockedSource->form_id,
                'user_id' => $lockedSource->user_id,
                'student_id' => $lockedSource->student_id,
                'signature' => $signature,
                'date_signed' => $dateSigned,
            ]);
            $revision->responseable()->associate($waiver);
            $revision->save();

            return $revision;
        });
    }

    public function canHandle(FormUser $source, User $user): bool
    {
        if ($source->user_id !== $user->id) {
            return false;
        }

        $source->loadMissing(['form', 'student', 'responseable']);

        return $source->form->form_type === FormTypes::StudentWaiver
            && $source->responseable instanceof StudentWaiver
            && $source->formCanBeUpdated();
    }

    public function waiverForRevision(FormUser $source, User $user): StudentWaiver
    {
        $source->loadMissing(['form', 'student', 'responseable']);
        $waiver = $source->responseable;

        if (! $this->canHandle($source, $user) || ! $waiver instanceof StudentWaiver) {
            throw new InvalidArgumentException('This medical waiver can no longer be updated.');
        }

        return $waiver;
    }

    private function validateRevisionSource(FormUser $source, User $user): void
    {
        if ($source->user_id !== $user->id) {
            throw new InvalidArgumentException('This form does not belong to your account.');
        }

        if (! $this->canHandle($source, $user)) {
            throw new InvalidArgumentException('This medical waiver can no longer be updated.');
        }
    }
}
