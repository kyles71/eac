<?php

declare(strict_types=1);

namespace App\Forms\Contracts;

use App\Enums\FormAnswerType;
use App\Enums\FormPurpose;
use App\Models\FormResponse;
use Illuminate\Database\Eloquent\Model;

interface FormMappingProvider
{
    public function supports(FormPurpose $purpose): bool;

    /**
     * @return array<string, string>
     */
    public function options(FormPurpose $purpose, ?FormAnswerType $answerType = null): array;

    public function validate(FormPurpose $purpose, FormAnswerType $answerType, ?string $mapping): void;

    public function project(FormResponse $response): ?Model;
}
