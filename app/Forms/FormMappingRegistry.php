<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\FormAnswerType;
use App\Enums\FormPurpose;
use App\Forms\Contracts\FormMappingProvider;
use App\Models\FormResponse;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class FormMappingRegistry
{
    /**
     * @return array<string, string>
     */
    public function options(FormPurpose $purpose, ?FormAnswerType $answerType = null): array
    {
        return $this->providers()
            ->filter(fn (FormMappingProvider $provider): bool => $provider->supports($purpose))
            ->flatMap(fn (FormMappingProvider $provider): array => $provider->options($purpose, $answerType))
            ->all();
    }

    public function validate(FormPurpose $purpose, FormAnswerType $answerType, ?string $mapping): void
    {
        if ($mapping === null) {
            return;
        }

        foreach ($this->providers() as $provider) {
            if (! $provider->supports($purpose)) {
                continue;
            }

            if (array_key_exists($mapping, $provider->options($purpose))) {
                $provider->validate($purpose, $answerType, $mapping);

                return;
            }
        }

        throw new InvalidArgumentException("Mapping [{$mapping}] is not registered for this form purpose.");
    }

    public function project(FormResponse $response): ?Model
    {
        $response->loadMissing('assignment.form');

        foreach ($this->providers() as $provider) {
            if ($provider->supports($response->assignment->form->purpose)) {
                return $provider->project($response);
            }
        }

        return null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, FormMappingProvider>
     */
    private function providers(): \Illuminate\Support\Collection
    {
        return collect(config('forms.mapping_providers', []))
            ->map(fn (string $provider): FormMappingProvider => app($provider));
    }
}
