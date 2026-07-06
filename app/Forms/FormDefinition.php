<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\FormAnswerType;
use App\Models\FormResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class FormDefinition
{
    /**
     * @param  array<int, array<string, mixed>>  $schema
     */
    public function validate(array $schema): void
    {
        $availableScalarKeys = [];
        $seenKeys = [];
        $this->validateBlocks($schema, $availableScalarKeys, $seenKeys);
    }

    /**
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<int, array{key: string, answer_type: FormAnswerType, mapping: ?string, label: string, block_key: ?string, sub_key: ?string}>
     */
    public function fields(array $schema): array
    {
        $fields = [];

        foreach ($schema as $block) {
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if ($type === 'section') {
                $fields = [...$fields, ...$this->fields($data['components'] ?? [])];

                continue;
            }

            if ($type === 'emergency_contacts') {
                $blockKey = $this->validKey($data['key'] ?? null);

                foreach ([
                    'name' => ['Emergency contact name', 'emergency_contact.name'],
                    'relationship' => ['Emergency contact relationship', 'emergency_contact.relationship'],
                    'phone_number' => ['Emergency contact phone number', 'emergency_contact.phone_number'],
                    'email' => ['Emergency contact email', 'emergency_contact.email'],
                    'wants_text_updates' => ['Emergency contact text updates', 'emergency_contact.wants_text_updates', FormAnswerType::Boolean],
                ] as $subKey => $definition) {
                    [$label, $mapping] = $definition;

                    $fields[] = [
                        'key' => "{$blockKey}.{$subKey}",
                        'answer_type' => $definition[2] ?? FormAnswerType::String,
                        'mapping' => $mapping,
                        'label' => $label,
                        'block_key' => $blockKey,
                        'sub_key' => $subKey,
                    ];
                }

                continue;
            }

            $answerType = $this->answerTypeForBlock($type);

            if ($answerType === null) {
                continue;
            }

            $fields[] = [
                'key' => $this->validKey($data['key'] ?? null),
                'answer_type' => $answerType,
                'mapping' => filled($data['mapping'] ?? null) ? (string) $data['mapping'] : null,
                'label' => (string) ($data['label'] ?? Str::headline($type)),
                'block_key' => null,
                'sub_key' => null,
            ];
        }

        return $fields;
    }

    /**
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<string, array<int, string>>
     */
    public function validationRules(array $schema, bool $requiresSignature): array
    {
        $rules = [];

        foreach ($schema as $block) {
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if ($type === 'section') {
                $rules = [...$rules, ...$this->validationRules($data['components'] ?? [], false)];

                continue;
            }

            if ($type === 'emergency_contacts') {
                $key = $this->validKey($data['key'] ?? null);
                $minimum = max(1, (int) ($data['min_items'] ?? 1));
                $rules["answers.{$key}"] = ['required', 'array', "min:{$minimum}"];
                $rules["answers.{$key}.*.name"] = ['required', 'string', 'max:255'];
                $rules["answers.{$key}.*.relationship"] = ['required', 'string', 'max:255'];
                $rules["answers.{$key}.*.phone_number"] = ['required', 'string', 'max:255'];
                $rules["answers.{$key}.*.email"] = ['required', 'email', 'max:255'];
                $rules["answers.{$key}.*.wants_text_updates"] = ['required', 'boolean'];

                continue;
            }

            $answerType = $this->answerTypeForBlock($type);

            if ($answerType === null) {
                continue;
            }

            $key = $this->validKey($data['key'] ?? null);
            $fieldRules = [];

            if (filled($data['visible_when_key'] ?? null)) {
                $dependentKey = $this->validKey($data['visible_when_key']);
                $expectedValue = (string) ($data['visible_when_value'] ?? '');
                $fieldRules[] = "exclude_unless:answers.{$dependentKey},{$expectedValue}";
            }

            $fieldRules[] = ($data['required'] ?? false) ? 'required' : 'nullable';
            $fieldRules[] = match ($answerType) {
                FormAnswerType::Boolean => 'boolean',
                FormAnswerType::Integer => 'integer',
                FormAnswerType::Decimal => 'numeric',
                FormAnswerType::Date, FormAnswerType::DateTime => 'date',
                default => 'string',
            };

            if ($answerType === FormAnswerType::String) {
                $fieldRules[] = 'max:255';
            }

            if ($type === 'email') {
                $fieldRules[] = 'email';
            }

            if ($type === 'checkbox' && ($data['accepted'] ?? false)) {
                $fieldRules[] = 'accepted';
            }

            $rules["answers.{$key}"] = $fieldRules;
        }

        if ($requiresSignature) {
            $rules['signature'] = ['required', 'string', 'max:255'];
            $rules['date_signed'] = ['required', 'date'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function responseState(FormResponse $response): array
    {
        $response->loadMissing(['answers.field', 'answerGroups.answers.field']);

        $answers = [];

        foreach ($response->answers->whereNull('form_answer_group_id') as $answer) {
            $answers[$answer->field->key] = $answer->value();
        }

        foreach ($response->answerGroups->sortBy('position') as $group) {
            $row = [];

            foreach ($group->answers as $answer) {
                $subKey = Str::after($answer->field->key, "{$group->block_key}.");
                $row[$subKey] = $answer->value();
            }

            $answers[$group->block_key][] = $row;
        }

        return [
            'answers' => $answers,
            'signature' => $response->signature,
            'date_signed' => $response->date_signed?->toDateString(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<string, mixed>|null
     */
    public function blockForField(array $schema, string $fieldKey): ?array
    {
        foreach ($schema as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if (($block['type'] ?? null) === 'section') {
                $match = $this->blockForField($data['components'] ?? [], $fieldKey);

                if ($match !== null) {
                    return $match;
                }
            }

            if (($data['key'] ?? null) === $fieldKey) {
                return $block;
            }
        }

        return null;
    }

    public function newKey(): string
    {
        return (string) Str::uuid();
    }

    private function answerTypeForBlock(string $blockType): ?FormAnswerType
    {
        return match ($blockType) {
            'short_text', 'email', 'phone', 'select', 'radio' => FormAnswerType::String,
            'long_text' => FormAnswerType::Text,
            'number' => FormAnswerType::Decimal,
            'checkbox', 'toggle' => FormAnswerType::Boolean,
            'date' => FormAnswerType::Date,
            default => null,
        };
    }

    private function validKey(mixed $key): string
    {
        if (! is_string($key) || ! Str::isUuid($key)) {
            throw new InvalidArgumentException('Every form builder component must have a stable UUID key.');
        }

        return $key;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<int, string>  $availableScalarKeys
     * @param  array<int, string>  $seenKeys
     */
    private function validateBlocks(array $blocks, array &$availableScalarKeys, array &$seenKeys): void
    {
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $key = $this->validKey($data['key'] ?? null);

            if (in_array($key, $seenKeys, true)) {
                throw new InvalidArgumentException('Every form builder component must have a unique stable key.');
            }

            $seenKeys[] = $key;

            if ($type === 'section') {
                if (! is_array($data['components'] ?? null)) {
                    throw new InvalidArgumentException('Every section must contain a components array.');
                }

                $this->validateBlocks($data['components'], $availableScalarKeys, $seenKeys);

                continue;
            }

            if (in_array($type, ['text', 'subject', 'student', 'emergency_contacts'], true)) {
                continue;
            }

            if ($this->answerTypeForBlock($type) === null) {
                throw new InvalidArgumentException("Unknown form builder block [{$type}].");
            }

            if (in_array($type, ['select', 'radio'], true) && blank($data['options'] ?? null)) {
                throw new InvalidArgumentException("The [{$type}] field [{$data['label']}] must contain options.");
            }

            if (filled($data['visible_when_key'] ?? null)) {
                $dependencyKey = $this->validKey($data['visible_when_key']);

                if (! in_array($dependencyKey, $availableScalarKeys, true)) {
                    throw new InvalidArgumentException('Visibility rules may only depend on an earlier scalar answer field.');
                }
            }

            $availableScalarKeys[] = $key;
        }
    }
}
