<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Enums\FormAnswerType;
use App\Enums\FormResponseStatus;
use App\Forms\FormDefinition;
use App\Models\FormAnswer;
use App\Models\FormAnswerGroup;
use App\Models\FormAssignment;
use App\Models\FormField;
use App\Models\FormResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class SaveFormResponseDraft
{
    public function __construct(private FormDefinition $definition) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public function handle(FormAssignment $assignment, array $state): FormResponse
    {
        return DB::transaction(function () use ($assignment, $state): FormResponse {
            /** @var FormAssignment $lockedAssignment */
            $lockedAssignment = FormAssignment::query()
                ->with(['form', 'version', 'latestSubmittedResponse'])
                ->lockForUpdate()
                ->findOrFail($assignment->getKey());

            $response = FormResponse::query()->firstOrNew([
                'form_assignment_id' => $lockedAssignment->id,
                'form_version_id' => $lockedAssignment->form_version_id,
                'status' => FormResponseStatus::Draft,
            ]);

            if (! $response->exists) {
                $response->revision_of_id = $lockedAssignment->latestSubmittedResponse?->id;
            }

            $response->signature = filled($state['signature'] ?? null) ? (string) $state['signature'] : null;
            $response->date_signed = filled($state['date_signed'] ?? null) ? $state['date_signed'] : null;
            $response->save();

            $this->syncAnswers($response, $lockedAssignment, $state);

            return $response->refresh()->load(['answers.field', 'answerGroups.answers.field']);
        });
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function syncAnswers(FormResponse $response, FormAssignment $assignment, array $state): void
    {
        $response->answers()->delete();
        $response->answerGroups()->delete();

        $fields = collect($this->definition->fields($assignment->version->schema))->keyBy('key');
        $models = FormField::query()
            ->where('form_id', $assignment->form_id)
            ->whereIn('key', $fields->keys())
            ->get()
            ->keyBy('key');
        $answerState = is_array($state['answers'] ?? null) ? $state['answers'] : [];

        foreach ($fields->whereNull('block_key') as $field) {
            if (! array_key_exists($field['key'], $answerState) || $answerState[$field['key']] === null || $answerState[$field['key']] === '') {
                continue;
            }

            $model = $models->get($field['key']);

            if ($model instanceof FormField) {
                $this->createAnswer($response, $model, $answerState[$field['key']]);
            }
        }

        foreach ($fields->whereNotNull('block_key')->groupBy('block_key') as $blockKey => $blockFields) {
            $rows = is_array($answerState[$blockKey] ?? null) ? $answerState[$blockKey] : [];

            foreach (array_values($rows) as $position => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $group = $response->answerGroups()->create([
                    'form_id' => $assignment->form_id,
                    'form_version_id' => $assignment->form_version_id,
                    'block_key' => $blockKey,
                    'position' => $position,
                ]);

                foreach ($blockFields as $field) {
                    $value = Arr::get($row, (string) $field['sub_key']);
                    $model = $models->get($field['key']);

                    if ($model instanceof FormField && $value !== null && $value !== '') {
                        $this->createAnswer($response, $model, $value, $group);
                    }
                }
            }
        }
    }

    private function createAnswer(
        FormResponse $response,
        FormField $field,
        mixed $value,
        ?FormAnswerGroup $group = null,
    ): FormAnswer {
        $column = match ($field->answer_type) {
            FormAnswerType::String => 'value_string',
            FormAnswerType::Text => 'value_text',
            FormAnswerType::Integer => 'value_integer',
            FormAnswerType::Decimal => 'value_decimal',
            FormAnswerType::Boolean => 'value_boolean',
            FormAnswerType::Date => 'value_date',
            FormAnswerType::DateTime => 'value_datetime',
        };

        return $response->answers()->create([
            'form_field_id' => $field->id,
            'form_answer_group_id' => $group?->id,
            $column => $value,
        ]);
    }
}
