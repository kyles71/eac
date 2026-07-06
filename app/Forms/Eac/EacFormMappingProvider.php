<?php

declare(strict_types=1);

namespace App\Forms\Eac;

use App\Enums\FormAnswerType;
use App\Enums\FormPurpose;
use App\Forms\Contracts\FormMappingProvider;
use App\Models\FormResponse;
use App\Models\ShowcaseParticipation;
use App\Models\StudentWaiver;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class EacFormMappingProvider implements FormMappingProvider
{
    public function supports(FormPurpose $purpose): bool
    {
        return in_array($purpose, [FormPurpose::MedicalWaiver, FormPurpose::ShowcaseParticipation], true);
    }

    public function options(FormPurpose $purpose, ?FormAnswerType $answerType = null): array
    {
        $options = match ($purpose) {
            FormPurpose::MedicalWaiver => [
                'student_waiver.student_home_address' => 'Student Waiver: Student Home Address',
                'student_waiver.signer_relationship' => 'Student Waiver: Signer Relationship',
                'student_waiver.medical_conditions' => 'Student Waiver: Medical Conditions',
                'student_waiver.allergies' => 'Student Waiver: Allergies',
                'student_waiver.past_injuries' => 'Student Waiver: Past Injuries',
                'student_waiver.medications' => 'Student Waiver: Medications',
                'student_waiver.medical_release_consent' => 'Student Waiver: Medical Release Consent',
                'student_waiver.behavioral_notes' => 'Student Waiver: Behavioral Notes',
                'student_waiver.medical_release_signed_on' => 'Student Waiver: Medical Release Signed On',
                'student_waiver.health_safety_policy_consent' => 'Student Waiver: Health & Safety Policy Consent',
                'student_waiver.health_safety_policy_signed_on' => 'Student Waiver: Health & Safety Policy Signed On',
                'student_waiver.media_release_consent' => 'Student Waiver: Media Release Consent',
                'student_waiver.media_release_signed_on' => 'Student Waiver: Media Release Signed On',
                'emergency_contact.name' => 'Emergency Contact: Name',
                'emergency_contact.relationship' => 'Emergency Contact: Relationship',
                'emergency_contact.phone_number' => 'Emergency Contact: Phone Number',
                'emergency_contact.email' => 'Emergency Contact: Email',
                'emergency_contact.wants_text_updates' => 'Emergency Contact: Text Updates',
            ],
            FormPurpose::ShowcaseParticipation => [
                'showcase_participation.is_participating' => 'Showcase Participation: Is Participating',
            ],
            FormPurpose::Generic => [],
        };

        if ($answerType === null) {
            return $options;
        }

        return collect($options)
            ->filter(fn (string $label, string $mapping): bool => $this->answerTypeForMapping($mapping) === $answerType)
            ->all();
    }

    public function validate(FormPurpose $purpose, FormAnswerType $answerType, ?string $mapping): void
    {
        if ($mapping === null) {
            return;
        }

        $expectedType = $this->answerTypeForMapping($mapping);
        $allowed = array_key_exists($mapping, $this->options($purpose));

        if (! $allowed || $expectedType !== $answerType) {
            throw new InvalidArgumentException("Mapping [{$mapping}] is not compatible with this form purpose and field type.");
        }
    }

    public function project(FormResponse $response): ?Model
    {
        $response->loadMissing(['assignment.form', 'answers.field', 'answerGroups.answers.field', 'projection']);

        return match ($response->assignment->form->purpose) {
            FormPurpose::MedicalWaiver => $this->projectMedicalWaiver($response),
            FormPurpose::ShowcaseParticipation => $this->projectShowcaseParticipation($response),
            FormPurpose::Generic => null,
        };
    }

    private function answerTypeForMapping(string $mapping): FormAnswerType
    {
        return match ($mapping) {
            'student_waiver.student_home_address',
            'student_waiver.medical_conditions',
            'student_waiver.allergies',
            'student_waiver.past_injuries',
            'student_waiver.medications',
            'student_waiver.behavioral_notes' => FormAnswerType::Text,
            'student_waiver.signer_relationship',
            'emergency_contact.name',
            'emergency_contact.relationship',
            'emergency_contact.phone_number',
            'emergency_contact.email' => FormAnswerType::String,
            'student_waiver.medical_release_consent',
            'student_waiver.health_safety_policy_consent',
            'student_waiver.media_release_consent',
            'showcase_participation.is_participating',
            'emergency_contact.wants_text_updates' => FormAnswerType::Boolean,
            'student_waiver.medical_release_signed_on',
            'student_waiver.health_safety_policy_signed_on',
            'student_waiver.media_release_signed_on' => FormAnswerType::Date,
            default => throw new InvalidArgumentException("Unknown form mapping [{$mapping}]."),
        };
    }

    private function projectMedicalWaiver(FormResponse $response): StudentWaiver
    {
        $waiver = $response->projection instanceof StudentWaiver
            ? $response->projection
            : StudentWaiver::query()->create();
        $attributes = [];

        foreach ($response->answers->whereNull('form_answer_group_id') as $answer) {
            $attribute = match ($answer->field->mapping) {
                'student_waiver.student_home_address' => 'student_home_address',
                'student_waiver.signer_relationship' => 'signer_relationship',
                'student_waiver.medical_conditions' => 'medical_conditions',
                'student_waiver.allergies' => 'allergies',
                'student_waiver.past_injuries' => 'past_injuries',
                'student_waiver.medications' => 'medications',
                'student_waiver.medical_release_consent' => 'medical_release_consent',
                'student_waiver.behavioral_notes' => 'behavioral_notes',
                'student_waiver.medical_release_signed_on' => 'medical_release_signed_on',
                'student_waiver.health_safety_policy_consent' => 'health_safety_policy_consent',
                'student_waiver.health_safety_policy_signed_on' => 'health_safety_policy_signed_on',
                'student_waiver.media_release_consent' => 'media_release_consent',
                'student_waiver.media_release_signed_on' => 'media_release_signed_on',
                default => null,
            };

            if ($attribute !== null) {
                $attributes[$attribute] = $answer->value();
            }
        }

        $waiver->update($attributes);
        $waiver->emergencyContacts()->delete();

        foreach ($response->answerGroups->sortBy('position') as $group) {
            $contact = [];

            foreach ($group->answers as $answer) {
                $attribute = match ($answer->field->mapping) {
                    'emergency_contact.name' => 'name',
                    'emergency_contact.relationship' => 'relationship',
                    'emergency_contact.phone_number' => 'phone_number',
                    'emergency_contact.email' => 'email',
                    'emergency_contact.wants_text_updates' => 'wants_text_updates',
                    default => null,
                };

                if ($attribute !== null) {
                    $contact[$attribute] = $answer->value();
                }
            }

            if ($contact !== []) {
                $waiver->emergencyContacts()->create($contact);
            }
        }

        $response->projection()->associate($waiver);
        $response->save();

        return $waiver;
    }

    private function projectShowcaseParticipation(FormResponse $response): ShowcaseParticipation
    {
        $participation = $response->projection instanceof ShowcaseParticipation
            ? $response->projection
            : ShowcaseParticipation::query()->create();
        $answer = $response->answers
            ->first(fn ($answer): bool => $answer->field->mapping === 'showcase_participation.is_participating');

        $participation->update(['is_participating' => (bool) $answer?->value()]);
        $response->projection()->associate($participation);
        $response->save();

        return $participation;
    }
}
