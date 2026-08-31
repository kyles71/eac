<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Kyle\FilamentFormBuilder\Contracts\FormMappingProvider;
use Kyle\FilamentFormBuilder\Enums\FormAnswerType;
use Kyle\FilamentFormBuilder\Models\Form;
use Kyle\FilamentFormBuilder\Models\FormResponse;
use Kyle\FilamentFormBuilder\Support\FormMapping;
use Kyle\FilamentFormBuilder\Support\FormProjectionTarget;

final class GenericFormMappingProviderForTest implements FormMappingProvider
{
    public function supports(Form $form): bool
    {
        return $form->key === 'generic-projection';
    }

    public function mappings(Form $form): array
    {
        return [
            new FormMapping(
                key: 'generic.answer',
                label: 'Generic Answer',
                answerType: FormAnswerType::String,
                target: 'respondent',
                attribute: 'first_name',
            ),
        ];
    }

    public function targets(Form $form): array
    {
        return [
            new FormProjectionTarget(
                key: 'respondent',
                label: 'Respondent',
                model: User::class,
                resolve: fn (FormResponse $response): User => $response->assignment->respondent,
                primary: true,
            ),
        ];
    }
}
