<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Enums\FormResponseStatus;
use App\Enums\FormUpdateStrategy;
use App\Forms\FormDefinition;
use App\Forms\FormMappingRegistry;
use App\Models\FormAssignment;
use App\Models\FormResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final readonly class SubmitFormResponse
{
    public function __construct(
        private FormDefinition $definition,
        private FormMappingRegistry $mappingRegistry,
        private SaveFormResponseDraft $saveDraft,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public function handle(FormAssignment $assignment, array $state): FormResponse
    {
        $assignment->loadMissing(['form', 'version']);

        if (! $assignment->isActive()) {
            throw new InvalidArgumentException('Expired form assignments cannot be submitted.');
        }

        if ($assignment->isCompleted() && ! $assignment->formCanBeUpdated()) {
            throw new InvalidArgumentException('This completed form assignment cannot be updated.');
        }

        Validator::make(
            $state,
            $this->definition->validationRules($assignment->version->schema, $assignment->version->requires_signature),
        )->validate();

        return DB::transaction(function () use ($assignment, $state): FormResponse {
            $response = $this->saveDraft->handle($assignment, $state);
            $previous = $response->revisionOf;

            if (
                $assignment->form->update_strategy === FormUpdateStrategy::InPlace
                && $previous instanceof FormResponse
                && $previous->projection !== null
            ) {
                $response->projection()->associate($previous->projection);
                $response->save();
            }

            $response->status = FormResponseStatus::Submitted;
            $response->submitted_at = now();
            $response->save();

            $this->mappingRegistry->project($response);

            if ($assignment->form->update_strategy === FormUpdateStrategy::InPlace && $previous instanceof FormResponse) {
                $previous->delete();
                $response->revision_of_id = null;
                $response->save();
            }

            return $response->refresh();
        });
    }
}
