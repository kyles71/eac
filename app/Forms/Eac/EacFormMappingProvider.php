<?php

declare(strict_types=1);

namespace App\Forms\Eac;

use App\Models\ShowcaseParticipation;
use App\Models\StudentWaiver;
use Kyle\FilamentFormBuilder\Contracts\FormMappingProvider;
use Kyle\FilamentFormBuilder\Enums\FormAnswerType;
use Kyle\FilamentFormBuilder\Models\Form;
use Kyle\FilamentFormBuilder\Models\FormResponse;
use Kyle\FilamentFormBuilder\Support\FormMapping;
use Kyle\FilamentFormBuilder\Support\FormProjectionTarget;

final readonly class EacFormMappingProvider implements FormMappingProvider
{
    public function supports(Form $form): bool
    {
        return in_array($form->key, ['student-waiver', 'showcase-participation'], true);
    }

    public function mappings(Form $form): array
    {
        return match ($form->key) {
            'student-waiver' => [
                $this->mapping('student_waiver.student_home_address', 'Student Home Address', FormAnswerType::Text, 'student_home_address'),
                $this->mapping('student_waiver.signer_relationship', 'Signer Relationship', FormAnswerType::String, 'signer_relationship'),
                $this->mapping('student_waiver.medical_conditions', 'Medical Conditions', FormAnswerType::Text, 'medical_conditions'),
                $this->mapping('student_waiver.allergies', 'Allergies', FormAnswerType::Text, 'allergies'),
                $this->mapping('student_waiver.past_injuries', 'Past Injuries', FormAnswerType::Text, 'past_injuries'),
                $this->mapping('student_waiver.medications', 'Medications', FormAnswerType::Text, 'medications'),
                $this->mapping('student_waiver.medical_release_consent', 'Medical Release Consent', FormAnswerType::Boolean, 'medical_release_consent'),
                $this->mapping('student_waiver.behavioral_notes', 'Behavioral Notes', FormAnswerType::Text, 'behavioral_notes'),
                $this->mapping('student_waiver.medical_release_signed_on', 'Medical Release Signed On', FormAnswerType::Date, 'medical_release_signed_on'),
                $this->mapping('student_waiver.health_safety_policy_consent', 'Health & Safety Policy Consent', FormAnswerType::Boolean, 'health_safety_policy_consent'),
                $this->mapping('student_waiver.health_safety_policy_signed_on', 'Health & Safety Policy Signed On', FormAnswerType::Date, 'health_safety_policy_signed_on'),
                $this->mapping('student_waiver.media_release_consent', 'Media Release Consent', FormAnswerType::Boolean, 'media_release_consent'),
                $this->mapping('student_waiver.media_release_signed_on', 'Media Release Signed On', FormAnswerType::Date, 'media_release_signed_on'),
            ],
            'showcase-participation' => [
                new FormMapping(
                    key: 'showcase_participation.is_participating',
                    label: 'Showcase Participation: Is Participating',
                    answerType: FormAnswerType::Boolean,
                    target: 'showcase_participation',
                    attribute: 'is_participating',
                ),
            ],
            default => [],
        };
    }

    public function targets(Form $form): array
    {
        return match ($form->key) {
            'student-waiver' => [
                new FormProjectionTarget(
                    key: 'student_waiver',
                    label: 'Student Waiver',
                    model: StudentWaiver::class,
                    resolve: fn (FormResponse $response): StudentWaiver => $response->projection instanceof StudentWaiver
                        ? $response->projection
                        : new StudentWaiver,
                    primary: true,
                ),
            ],
            'showcase-participation' => [
                new FormProjectionTarget(
                    key: 'showcase_participation',
                    label: 'Showcase Participation',
                    model: ShowcaseParticipation::class,
                    resolve: fn (FormResponse $response): ShowcaseParticipation => $response->projection instanceof ShowcaseParticipation
                        ? $response->projection
                        : new ShowcaseParticipation,
                    primary: true,
                ),
            ],
            default => [],
        };
    }

    private function mapping(string $key, string $label, FormAnswerType $answerType, string $attribute): FormMapping
    {
        return new FormMapping(
            key: $key,
            label: "Student Waiver: {$label}",
            answerType: $answerType,
            target: 'student_waiver',
            attribute: $attribute,
        );
    }
}
