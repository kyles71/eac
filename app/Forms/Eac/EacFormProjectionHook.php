<?php

declare(strict_types=1);

namespace App\Forms\Eac;

use App\Models\StudentWaiver;
use Illuminate\Database\Eloquent\Model;
use Kyle\FilamentFormBuilder\Contracts\FormProjectionHook;
use Kyle\FilamentFormBuilder\Models\Form;
use Kyle\FilamentFormBuilder\Models\FormAnswerGroup;
use Kyle\FilamentFormBuilder\Models\FormResponse;
use Kyle\FilamentFormBuilder\Support\FormDefinition;

final readonly class EacFormProjectionHook implements FormProjectionHook
{
    public function __construct(private FormDefinition $definition) {}

    public function supports(Form $form): bool
    {
        return $form->key === 'student-waiver';
    }

    /** @param array<string, Model> $targets */
    public function project(FormResponse $response, array $targets): void
    {
        $waiver = $targets['student_waiver'] ?? null;

        if (! $waiver instanceof StudentWaiver) {
            return;
        }

        $response->loadMissing(['version', 'answerGroups.answers.field']);
        $waiver->emergencyContacts()->delete();

        foreach ($response->answerGroups->sortBy('position') as $group) {
            if (! $this->isEmergencyContactsGroup($response, $group)) {
                continue;
            }

            $contact = [];

            foreach ($group->answers as $answer) {
                if ($answer->field->sub_key !== null) {
                    $contact[$answer->field->sub_key] = $answer->value();
                }
            }

            if ($contact !== []) {
                $waiver->emergencyContacts()->create($contact);
            }
        }
    }

    private function isEmergencyContactsGroup(FormResponse $response, FormAnswerGroup $group): bool
    {
        $field = $group->answers->first()?->field;

        if ($field === null) {
            return false;
        }

        $block = $this->definition->blockForField($response->version->schema, $field->key);

        return ($block['type'] ?? null) === 'emergency_contacts';
    }
}
