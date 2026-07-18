<?php

declare(strict_types=1);

namespace App\Forms\Migration;

use App\Forms\DefaultFormDefinitions;
use App\Forms\Eac\EacFormContentProvider;
use App\Models\ShowcaseParticipation;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kyle\FilamentFormBuilder\Enums\FormAnswerType;
use Kyle\FilamentFormBuilder\Enums\FormResponseStatus;
use Kyle\FilamentFormBuilder\Enums\FormUpdateStrategy;
use Kyle\FilamentFormBuilder\Enums\FormVersionStatus;
use Kyle\FilamentFormBuilder\Support\FormDefinition;
use Kyle\FilamentFormBuilder\Support\FormTableRegistry;
use RuntimeException;
use stdClass;

/**
 * @phpstan-type VersionDefinition array{id: int, logical_key: string, version: int, version_key: string, label: string, schema: array<int, array<string, mixed>>, activation_starts_at: mixed, activation_ends_at: mixed, created_at: mixed, updated_at: mixed, source_form_ids: list<int>}
 */
final readonly class LegacyFormMigration
{
    /** @var list<string> */
    private const array SourceTables = [
        'forms',
        'form_users',
        'student_waivers',
        'showcase_participations',
        'emergency_contacts',
        'course_forms',
        'users',
        'students',
        'courses',
    ];

    public function __construct(
        private DefaultFormDefinitions $definitions,
        private EacFormContentProvider $content,
        private FormDefinition $formDefinition,
        private FormTableRegistry $tables,
    ) {}

    /**
     * @return array{source_present: bool, counts: array<string, int>, broadened_phone_values: list<string>}
     */
    public function preflight(): array
    {
        if (! Schema::hasTable('forms')) {
            return ['source_present' => false, 'counts' => [], 'broadened_phone_values' => []];
        }

        $this->assertSourceTablesExist();
        $forms = $this->sourceForms();
        $formUsers = DB::table('form_users')->orderBy('id')->get();
        $allowedTypes = [$this->waiverType(), $this->showcaseType()];
        $unknownType = $forms->first(fn (object $form): bool => ! in_array($form->form_type, $allowedTypes, true));

        if ($unknownType !== null) {
            throw new RuntimeException("Legacy form [{$unknownType->id}] has unknown type [{$unknownType->form_type}].");
        }

        $formsById = $forms->keyBy('id');

        foreach ($formUsers as $formUser) {
            $form = $formsById->get($formUser->form_id);

            if ($form === null) {
                throw new RuntimeException("Legacy form user [{$formUser->id}] references missing form [{$formUser->form_id}].");
            }

            if (! DB::table('users')->where('id', $formUser->user_id)->exists()) {
                throw new RuntimeException("Legacy form user [{$formUser->id}] references missing user [{$formUser->user_id}].");
            }

            if ($formUser->student_id === null || ! DB::table('students')->where('id', $formUser->student_id)->exists()) {
                throw new RuntimeException("Legacy form user [{$formUser->id}] does not reference an existing student.");
            }

            $this->assertProjectionIsConsistent($formUser, $form);
        }

        foreach (DB::table('course_forms')->get() as $courseForm) {
            if (! DB::table('courses')->where('id', $courseForm->course_id)->exists()) {
                throw new RuntimeException("Legacy course form [{$courseForm->id}] references missing course [{$courseForm->course_id}].");
            }

            if (! $formsById->has($courseForm->form_id)) {
                throw new RuntimeException("Legacy course form [{$courseForm->id}] references missing form [{$courseForm->form_id}].");
            }
        }

        $this->content->currentHealthSafetyPolicyReference();
        $this->content->currentTextMessageUpdatesPolicyReference();
        $this->assertDestinationHasNoCollisions($forms, $formUsers);

        return [
            'source_present' => true,
            'counts' => collect(self::SourceTables)
                ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
                ->all(),
            'broadened_phone_values' => DB::table('emergency_contacts')
                ->orderBy('id')
                ->pluck('phone_number')
                ->filter(fn (mixed $phone): bool => is_string($phone)
                    && preg_match('/^\(\d{3}\) \d{3}-\d{4}$/', $phone) !== 1)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    public function migrate(): void
    {
        $report = $this->preflight();

        if (! $report['source_present']) {
            return;
        }

        $forms = $this->sourceForms();
        $formUsers = DB::table('form_users')->orderBy('id')->get();
        $versionDefinitions = $this->versionDefinitions($forms);
        $legacyFormMap = collect($versionDefinitions)
            ->mapWithKeys(fn (array $definition): array => collect($definition['source_form_ids'])
                ->mapWithKeys(fn (int $sourceId): array => [$sourceId => $definition])
                ->all());
        $logicalForms = $this->logicalForms($forms);

        DB::transaction(function () use ($formUsers, $legacyFormMap, $logicalForms, $versionDefinitions): void {
            $this->upsertLogicalForms($logicalForms);
            $this->upsertVersions($versionDefinitions);
            $this->synchronizeFields($logicalForms);
            $this->upsertAssignmentsAndResponses($formUsers, $legacyFormMap, $logicalForms);
            $this->rebuildAnswers($formUsers, $legacyFormMap, $logicalForms);
            $this->setActiveVersions($logicalForms, $versionDefinitions);
        });

        $this->remapCourseForms($legacyFormMap, $logicalForms);
        $this->verify();
    }

    /** @return array{responses: int, responseable_projections: int, emergency_contacts: int, course_forms: int} */
    public function verify(): array
    {
        if (! Schema::hasTable('forms')) {
            return ['responses' => 0, 'responseable_projections' => 0, 'emergency_contacts' => 0, 'course_forms' => 0];
        }

        $responseIds = DB::table('form_users')->pluck('id');
        $responsesTable = $this->tables->name('responses');
        $groupsTable = $this->tables->name('answer_groups');
        $sourceResponseCount = $responseIds->count();
        $destinationResponseCount = DB::table($responsesTable)->whereIn('id', $responseIds)->count();

        if ($sourceResponseCount !== $destinationResponseCount) {
            throw new RuntimeException("Legacy response verification failed ({$sourceResponseCount} source, {$destinationResponseCount} destination).");
        }

        $projectionCount = DB::table('form_users')->whereNotNull('responseable_id')->count();
        $migratedProjectionCount = DB::table("{$responsesTable} as response")
            ->join('form_users as source', 'source.id', '=', 'response.id')
            ->whereColumn('response.projection_type', 'source.responseable_type')
            ->whereColumn('response.projection_id', 'source.responseable_id')
            ->count();

        if ($projectionCount !== $migratedProjectionCount) {
            throw new RuntimeException('One or more legacy response projections are no longer reachable.');
        }

        $contactCount = DB::table('emergency_contacts')->count();
        $groupCount = DB::table($groupsTable)->whereIn('form_response_id', $responseIds)->count();

        if ($contactCount !== $groupCount) {
            throw new RuntimeException("Emergency-contact verification failed ({$contactCount} source, {$groupCount} destination).");
        }

        $this->assertNoOrphans();

        return [
            'responses' => $destinationResponseCount,
            'responseable_projections' => $migratedProjectionCount,
            'emergency_contacts' => $groupCount,
            'course_forms' => DB::table('course_forms')->count(),
        ];
    }

    private function assertSourceTablesExist(): void
    {
        foreach (self::SourceTables as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Legacy preflight cannot continue because source table [{$table}] is missing.");
            }
        }
    }

    private function assertProjectionIsConsistent(object $formUser, object $form): void
    {
        if (($formUser->responseable_type === null) !== ($formUser->responseable_id === null)) {
            throw new RuntimeException("Legacy form user [{$formUser->id}] has an incomplete responseable morph.");
        }

        if ($formUser->responseable_id === null) {
            return;
        }

        $expectedType = $form->form_type;

        if ($formUser->responseable_type !== $expectedType) {
            throw new RuntimeException("Legacy form user [{$formUser->id}] has responseable type [{$formUser->responseable_type}], expected [{$expectedType}].");
        }

        $table = $expectedType === $this->waiverType() ? 'student_waivers' : 'showcase_participations';

        if (! DB::table($table)->where('id', $formUser->responseable_id)->exists()) {
            throw new RuntimeException("Legacy form user [{$formUser->id}] references missing projection [{$expectedType}:{$formUser->responseable_id}].");
        }
    }

    private function assertDestinationHasNoCollisions(Collection $forms, Collection $formUsers): void
    {
        $formsTable = $this->tables->name('forms');
        $responsesTable = $this->tables->name('responses');

        if (! Schema::hasTable($formsTable) || ! Schema::hasTable($responsesTable)) {
            return;
        }

        $expectedForms = $this->logicalForms($forms);
        $unexpectedForm = DB::table($formsTable)
            ->whereNotIn('key', array_keys($expectedForms))
            ->first();

        if ($unexpectedForm !== null) {
            throw new RuntimeException("Destination form [{$unexpectedForm->id}] collides with the legacy conversion.");
        }

        foreach ($expectedForms as $key => $definition) {
            $existing = DB::table($formsTable)->where('key', $key)->first();

            if ($existing !== null && (int) $existing->id !== $definition['id']) {
                throw new RuntimeException("Destination form key [{$key}] already uses unexpected ID [{$existing->id}].");
            }
        }

        $sourceResponseIds = $formUsers->pluck('id');
        $unexpectedResponse = DB::table($responsesTable)
            ->when($sourceResponseIds->isNotEmpty(), fn (Builder $query): Builder => $query->whereNotIn('id', $sourceResponseIds))
            ->first();

        if ($unexpectedResponse !== null) {
            throw new RuntimeException("Destination response [{$unexpectedResponse->id}] collides with the legacy conversion.");
        }
    }

    /** @return Collection<int, stdClass> */
    private function sourceForms(): Collection
    {
        return DB::table('forms')->orderBy('id')->get();
    }

    /**
     * @return array<string, array{id: int, key: string, name: string, updates_allowed: bool, update_strategy: string|null}>
     */
    private function logicalForms(Collection $forms): array
    {
        return collect([
            'student-waiver' => [$this->waiverType(), 'Student Waiver', true, FormUpdateStrategy::Revision->value],
            'showcase-participation' => [$this->showcaseType(), 'Showcase Participation', false, null],
        ])->mapWithKeys(function (array $settings, string $key) use ($forms): array {
            [$type, $name, $updatesAllowed, $updateStrategy] = $settings;
            $id = (int) $forms->where('form_type', $type)->min('id');

            return [$key => compact('id', 'key', 'name', 'updatesAllowed', 'updateStrategy')];
        })->map(fn (array $definition): array => [
            'id' => $definition['id'],
            'key' => $definition['key'],
            'name' => $definition['name'],
            'updates_allowed' => $definition['updatesAllowed'],
            'update_strategy' => $definition['updateStrategy'],
        ])->all();
    }

    /**
     * @return list<array{id: int, logical_key: string, version: int, version_key: string, label: string, schema: array<int, array<string, mixed>>, activation_starts_at: mixed, activation_ends_at: mixed, created_at: mixed, updated_at: mixed, source_form_ids: list<int>}>
     */
    private function versionDefinitions(Collection $forms): array
    {
        $healthReference = $this->content->currentHealthSafetyPolicyReference();
        $textReference = $this->content->currentTextMessageUpdatesPolicyReference();
        $schemas = [
            'student-waiver' => $this->definitions->medicalWaiver($healthReference, $textReference),
            'showcase-participation' => $this->definitions->showcaseParticipation(),
        ];
        $groups = $forms->groupBy(fn (object $form): string => $this->logicalKey($form).':'.$this->legacyVersionKey($form));
        $definitions = [];

        foreach (['student-waiver', 'showcase-participation'] as $logicalKey) {
            $logicalGroups = $groups
                ->filter(fn (Collection $group): bool => $this->logicalKey($group->first()) === $logicalKey)
                ->sortBy(fn (Collection $group): string => (string) ($group->min('valid_until') ?? $group->min('created_at')).':'.mb_str_pad((string) $group->min('id'), 20, '0', STR_PAD_LEFT))
                ->values();

            foreach ($logicalGroups as $index => $group) {
                $first = $group->sortBy('id')->first();
                $versionKey = $this->legacyVersionKey($first);
                $season = $logicalKey === 'student-waiver' ? $this->seasonFromValidUntil($first->valid_until) : null;
                $start = $season === null ? $group->min('created_at') : $this->seasonStart($season);
                $end = $group->max('valid_until');

                $definitions[] = [
                    'id' => (int) $group->min('id'),
                    'logical_key' => $logicalKey,
                    'version' => $index + 1,
                    'version_key' => $versionKey,
                    'label' => $season === null
                        ? (string) ($first->name ?: "Legacy {$first->id}")
                        : "September {$season[0]} – August {$season[1]}",
                    'schema' => $schemas[$logicalKey],
                    'activation_starts_at' => $start,
                    'activation_ends_at' => $end,
                    'created_at' => $group->min('created_at'),
                    'updated_at' => $group->max('updated_at'),
                    'source_form_ids' => $group->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                ];
            }
        }

        return $definitions;
    }

    /** @param array<string, array<string, mixed>> $logicalForms */
    private function upsertLogicalForms(array $logicalForms): void
    {
        foreach ($logicalForms as $definition) {
            DB::table($this->tables->name('forms'))->updateOrInsert(
                ['id' => $definition['id']],
                [
                    'key' => $definition['key'],
                    'name' => $definition['name'],
                    'updates_allowed' => $definition['updates_allowed'],
                    'update_strategy' => $definition['update_strategy'],
                    'owner_type' => null,
                    'owner_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /** @param list<array<string, mixed>> $definitions */
    private function upsertVersions(array $definitions): void
    {
        $forms = DB::table($this->tables->name('forms'))->pluck('id', 'key');

        foreach ($definitions as $definition) {
            DB::table($this->tables->name('versions'))->updateOrInsert(
                ['id' => $definition['id']],
                [
                    'form_id' => $forms[$definition['logical_key']],
                    'version' => $definition['version'],
                    'version_key' => $definition['version_key'],
                    'label' => $definition['label'],
                    'status' => FormVersionStatus::Published->value,
                    'draft_marker' => null,
                    'schema' => json_encode($definition['schema'], JSON_THROW_ON_ERROR),
                    'requires_signature' => true,
                    'require_completed_again' => true,
                    'activation_starts_at' => $definition['activation_starts_at'],
                    'activation_ends_at' => $definition['activation_ends_at'],
                    'activated_at' => $definition['activation_starts_at'],
                    'deactivated_at' => $definition['activation_ends_at'] !== null && Carbon::parse($definition['activation_ends_at'])->isPast()
                        ? $definition['activation_ends_at']
                        : null,
                    'published_by_type' => null,
                    'published_by_id' => null,
                    'published_at' => $definition['activation_starts_at'] ?? $definition['created_at'],
                    'created_at' => $definition['created_at'],
                    'updated_at' => $definition['updated_at'],
                ],
            );
        }
    }

    /** @param array<string, array<string, mixed>> $logicalForms */
    private function synchronizeFields(array $logicalForms): void
    {
        $schemas = [
            'student-waiver' => $this->definitions->medicalWaiver(
                $this->content->currentHealthSafetyPolicyReference(),
                $this->content->currentTextMessageUpdatesPolicyReference(),
            ),
            'showcase-participation' => $this->definitions->showcaseParticipation(),
        ];

        foreach ($logicalForms as $key => $form) {
            foreach ($this->formDefinition->fields($schemas[$key]) as $field) {
                DB::table($this->tables->name('fields'))->updateOrInsert(
                    ['form_id' => $form['id'], 'key' => $field['key']],
                    [
                        'answer_type' => $field['answer_type']->value,
                        'mapping' => $field['mapping'],
                        'label' => $field['label'],
                        'block_key' => $field['block_key'],
                        'sub_key' => $field['sub_key'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    /**
     * @param  Collection<int, stdClass>  $formUsers
     * @param  Collection<int, VersionDefinition>  $legacyFormMap
     * @param  array<string, array<string, mixed>>  $logicalForms
     */
    private function upsertAssignmentsAndResponses(Collection $formUsers, Collection $legacyFormMap, array $logicalForms): void
    {
        $userType = (new User)->getMorphClass();
        $studentType = (new Student)->getMorphClass();
        $assignmentsTable = $this->tables->name('assignments');
        $responsesTable = $this->tables->name('responses');
        $groups = $formUsers->groupBy(function (object $formUser) use ($legacyFormMap): string {
            $version = $legacyFormMap->get($formUser->form_id);

            return $version['logical_key'].':'.$formUser->student_id;
        });

        foreach ($groups as $rows) {
            $first = $rows->sortBy('id')->first();
            $logicalKey = $legacyFormMap->get($first->form_id)['logical_key'];
            $assignmentId = (int) $rows->min('id');
            $selected = $this->assignmentPointerRow($rows, $legacyFormMap);
            $version = $legacyFormMap->get($selected->form_id);

            DB::table($assignmentsTable)->updateOrInsert(['id' => $assignmentId], [
                'form_id' => $logicalForms[$logicalKey]['id'],
                'form_version_id' => $version['id'],
                'respondent_type' => $userType,
                'respondent_id' => $selected->user_id,
                'subject_type' => $studentType,
                'subject_id' => $selected->student_id,
                'created_at' => $rows->min('created_at'),
                'updated_at' => $rows->max('updated_at'),
            ]);

            $previousSubmittedByVersion = [];

            foreach ($rows->sortBy(fn (object $row): string => $row->updated_at.':'.mb_str_pad((string) $row->id, 20, '0', STR_PAD_LEFT)) as $row) {
                $rowVersion = $legacyFormMap->get($row->form_id);
                $submitted = $this->isSubmitted($row);
                $previous = $previousSubmittedByVersion[$rowVersion['id']] ?? null;

                DB::table($responsesTable)->updateOrInsert(['id' => $row->id], [
                    'form_assignment_id' => $assignmentId,
                    'form_version_id' => $rowVersion['id'],
                    'revision_of_id' => $previous,
                    'status' => $submitted ? FormResponseStatus::Submitted->value : FormResponseStatus::Draft->value,
                    'signature' => $row->signature,
                    'date_signed' => $row->date_signed,
                    'projection_type' => $row->responseable_type,
                    'projection_id' => $row->responseable_id,
                    'submitted_by_type' => $userType,
                    'submitted_by_id' => $row->user_id,
                    'submitted_at' => $submitted ? $row->updated_at : null,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);

                if ($submitted) {
                    $previousSubmittedByVersion[$rowVersion['id']] = (int) $row->id;
                }
            }
        }
    }

    /**
     * @param  Collection<int, stdClass>  $formUsers
     * @param  Collection<int, VersionDefinition>  $legacyFormMap
     * @param  array<string, array<string, mixed>>  $logicalForms
     */
    private function rebuildAnswers(Collection $formUsers, Collection $legacyFormMap, array $logicalForms): void
    {
        $responseIds = $formUsers->pluck('id');
        DB::table($this->tables->name('answers'))->whereIn('form_response_id', $responseIds)->delete();
        DB::table($this->tables->name('answer_groups'))->whereIn('form_response_id', $responseIds)->delete();
        $fieldIds = DB::table($this->tables->name('fields'))
            ->get()
            ->keyBy(fn (object $field): string => $field->form_id.':'.$field->key);

        foreach ($formUsers as $formUser) {
            if ($formUser->responseable_id === null) {
                continue;
            }

            $version = $legacyFormMap->get($formUser->form_id);
            $logicalKey = $version['logical_key'];
            $formId = $logicalForms[$logicalKey]['id'];

            if ($logicalKey === 'student-waiver') {
                $waiver = DB::table('student_waivers')->where('id', $formUser->responseable_id)->first();
                $this->insertWaiverAnswers((int) $formUser->id, $formId, $fieldIds, $waiver);
                $this->insertEmergencyContactAnswers((int) $formUser->id, $formId, $version['id'], $fieldIds, (int) $formUser->responseable_id);
            } else {
                $participation = DB::table('showcase_participations')->where('id', $formUser->responseable_id)->first();
                $this->insertAnswer(
                    (int) $formUser->id,
                    $fieldIds[$formId.':'.DefaultFormDefinitions::ShowcaseParticipation]->id,
                    FormAnswerType::Boolean,
                    $participation->is_participating,
                );
            }
        }
    }

    /** @param Collection<string, stdClass> $fieldIds */
    private function insertWaiverAnswers(int $responseId, int $formId, Collection $fieldIds, object $waiver): void
    {
        $fields = [
            DefaultFormDefinitions::StudentHomeAddress => ['student_home_address', FormAnswerType::Text],
            DefaultFormDefinitions::SignerRelationship => ['signer_relationship', FormAnswerType::String],
            DefaultFormDefinitions::MedicalConditions => ['medical_conditions', FormAnswerType::Text],
            DefaultFormDefinitions::Allergies => ['allergies', FormAnswerType::Text],
            DefaultFormDefinitions::PastInjuries => ['past_injuries', FormAnswerType::Text],
            DefaultFormDefinitions::Medications => ['medications', FormAnswerType::Text],
            DefaultFormDefinitions::MedicalReleaseConsent => ['medical_release_consent', FormAnswerType::Boolean],
            DefaultFormDefinitions::BehavioralNotes => ['behavioral_notes', FormAnswerType::Text],
            DefaultFormDefinitions::MedicalReleaseSignedOn => ['medical_release_signed_on', FormAnswerType::Date],
            DefaultFormDefinitions::HealthSafetyPolicyConsent => ['health_safety_policy_consent', FormAnswerType::Boolean],
            DefaultFormDefinitions::HealthSafetyPolicySignedOn => ['health_safety_policy_signed_on', FormAnswerType::Date],
            DefaultFormDefinitions::MediaReleaseConsent => ['media_release_consent', FormAnswerType::Boolean],
            DefaultFormDefinitions::MediaReleaseSignedOn => ['media_release_signed_on', FormAnswerType::Date],
        ];

        foreach ($fields as $key => [$attribute, $type]) {
            $this->insertAnswer($responseId, $fieldIds[$formId.':'.$key]->id, $type, $waiver->{$attribute});
        }
    }

    /** @param Collection<string, stdClass> $fieldIds */
    private function insertEmergencyContactAnswers(
        int $responseId,
        int $formId,
        int $versionId,
        Collection $fieldIds,
        int $waiverId,
    ): void {
        $contacts = DB::table('emergency_contacts')->where('student_waiver_id', $waiverId)->orderBy('id')->get();
        $types = [
            'name' => FormAnswerType::String,
            'relationship' => FormAnswerType::String,
            'phone_number' => FormAnswerType::String,
            'email' => FormAnswerType::String,
            'wants_text_updates' => FormAnswerType::Boolean,
        ];

        foreach ($contacts->values() as $position => $contact) {
            DB::table($this->tables->name('answer_groups'))->insert([
                'id' => $contact->id,
                'form_id' => $formId,
                'form_version_id' => $versionId,
                'form_response_id' => $responseId,
                'block_key' => DefaultFormDefinitions::EmergencyContacts,
                'position' => $position,
                'created_at' => $contact->created_at,
                'updated_at' => $contact->updated_at,
            ]);

            foreach ($types as $subKey => $type) {
                $this->insertAnswer(
                    $responseId,
                    $fieldIds[$formId.':'.DefaultFormDefinitions::EmergencyContacts.'.'.$subKey]->id,
                    $type,
                    $contact->{$subKey},
                    (int) $contact->id,
                );
            }
        }
    }

    private function insertAnswer(int $responseId, int $fieldId, FormAnswerType $type, mixed $value, ?int $groupId = null): void
    {
        $columns = [
            'value_string' => null,
            'value_text' => null,
            'value_integer' => null,
            'value_decimal' => null,
            'value_boolean' => null,
            'value_date' => null,
            'value_datetime' => null,
        ];
        $column = match ($type) {
            FormAnswerType::String => 'value_string',
            FormAnswerType::Text => 'value_text',
            FormAnswerType::Integer => 'value_integer',
            FormAnswerType::Decimal => 'value_decimal',
            FormAnswerType::Boolean => 'value_boolean',
            FormAnswerType::Date => 'value_date',
            FormAnswerType::DateTime => 'value_datetime',
        };
        $columns[$column] = $value;

        DB::table($this->tables->name('answers'))->insert([
            'form_response_id' => $responseId,
            'form_field_id' => $fieldId,
            'form_answer_group_id' => $groupId,
            ...$columns,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $logicalForms
     * @param  list<array<string, mixed>>  $versions
     */
    private function setActiveVersions(array $logicalForms, array $versions): void
    {
        foreach ($logicalForms as $key => $form) {
            $active = collect($versions)
                ->where('logical_key', $key)
                ->filter(fn (array $version): bool => Carbon::parse($version['activation_starts_at'])->lessThanOrEqualTo(now())
                    && ($version['activation_ends_at'] === null || Carbon::parse($version['activation_ends_at'])->isFuture()))
                ->sortBy(fn (array $version): string => $version['activation_starts_at'].':'.mb_str_pad((string) $version['version'], 10, '0', STR_PAD_LEFT))
                ->last();

            DB::table($this->tables->name('forms'))
                ->where('id', $form['id'])
                ->update(['active_version_id' => $active['id'] ?? null]);
        }
    }

    /**
     * @param  Collection<int, VersionDefinition>  $legacyFormMap
     * @param  array<string, array<string, mixed>>  $logicalForms
     */
    private function remapCourseForms(Collection $legacyFormMap, array $logicalForms): void
    {
        $rows = DB::table('course_forms')->orderBy('id')->get()
            ->map(function (object $row) use ($legacyFormMap, $logicalForms): array {
                $logicalKey = $legacyFormMap->get($row->form_id)['logical_key'];

                return [
                    'id' => (int) $row->id,
                    'course_id' => (int) $row->course_id,
                    'form_id' => $logicalForms[$logicalKey]['id'],
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            })
            ->groupBy(fn (array $row): string => $row['course_id'].':'.$row['form_id'])
            ->map(fn (Collection $duplicates): array => $duplicates->sortBy('id')->first())
            ->values()
            ->all();

        $foreignKey = collect(Schema::getForeignKeys('course_forms'))
            ->first(fn (array $key): bool => $key['columns'] === ['form_id']);

        if ($foreignKey !== null && $foreignKey['foreign_table'] !== $this->tables->name('forms')) {
            Schema::table('course_forms', function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign($foreignKey['name'] ?? ['form_id']);
            });
        }

        DB::table('course_forms')->delete();

        if ($rows !== []) {
            DB::table('course_forms')->insert($rows);
        }

        $currentForeignKey = collect(Schema::getForeignKeys('course_forms'))
            ->first(fn (array $key): bool => $key['columns'] === ['form_id']);

        if ($currentForeignKey === null) {
            Schema::table('course_forms', function (Blueprint $table): void {
                $table->foreign('form_id')
                    ->references('id')
                    ->on($this->tables->name('forms'))
                    ->cascadeOnDelete();
            });
        }
    }

    private function assertNoOrphans(): void
    {
        $tables = [
            'forms' => $this->tables->name('forms'),
            'versions' => $this->tables->name('versions'),
            'fields' => $this->tables->name('fields'),
            'assignments' => $this->tables->name('assignments'),
            'responses' => $this->tables->name('responses'),
            'answer_groups' => $this->tables->name('answer_groups'),
            'answers' => $this->tables->name('answers'),
        ];
        $checks = [
            [$tables['versions'], 'form_id', $tables['forms'], false],
            [$tables['assignments'], 'form_id', $tables['forms'], false],
            [$tables['assignments'], 'form_version_id', $tables['versions'], false],
            [$tables['responses'], 'form_assignment_id', $tables['assignments'], false],
            [$tables['responses'], 'form_version_id', $tables['versions'], false],
            [$tables['responses'], 'revision_of_id', $tables['responses'], true],
            [$tables['answer_groups'], 'form_response_id', $tables['responses'], false],
            [$tables['answers'], 'form_response_id', $tables['responses'], false],
            [$tables['answers'], 'form_field_id', $tables['fields'], false],
            [$tables['answers'], 'form_answer_group_id', $tables['answer_groups'], true],
        ];

        foreach ($checks as [$child, $foreignKey, $parent, $nullable]) {
            $orphans = DB::table("{$child} as child")
                ->when($nullable, fn (Builder $query): Builder => $query->whereNotNull("child.{$foreignKey}"))
                ->whereNotExists(fn (Builder $query): Builder => $query
                    ->selectRaw('1')
                    ->from("{$parent} as parent")
                    ->whereColumn('parent.id', "child.{$foreignKey}"))
                ->count();

            if ($orphans !== 0) {
                throw new RuntimeException("Migrated relationship [{$child}.{$foreignKey}] contains {$orphans} orphaned rows.");
            }
        }
    }

    /** @param Collection<int, stdClass> $rows @param Collection<int, VersionDefinition> $legacyFormMap */
    private function assignmentPointerRow(Collection $rows, Collection $legacyFormMap): object
    {
        $incomplete = $rows->reject(fn (object $row): bool => $this->isSubmitted($row));
        $candidates = $incomplete->isNotEmpty() ? $incomplete : $rows;

        return $candidates->sortBy(function (object $row) use ($legacyFormMap): string {
            $version = $legacyFormMap->get($row->form_id);

            return mb_str_pad((string) $version['version'], 10, '0', STR_PAD_LEFT).':'.$row->updated_at.':'.mb_str_pad((string) $row->id, 20, '0', STR_PAD_LEFT);
        })->last();
    }

    private function isSubmitted(object $formUser): bool
    {
        return filled($formUser->signature) && $formUser->date_signed !== null;
    }

    private function logicalKey(object $form): string
    {
        return $form->form_type === $this->waiverType() ? 'student-waiver' : 'showcase-participation';
    }

    private function legacyVersionKey(object $form): string
    {
        if ($form->form_type === $this->showcaseType()) {
            return "legacy-{$form->id}";
        }

        $season = $this->seasonFromValidUntil($form->valid_until);

        return $season === null ? "legacy-{$form->id}" : "{$season[0]}-{$season[1]}";
    }

    /** @return array{int, int}|null */
    private function seasonFromValidUntil(mixed $validUntil): ?array
    {
        if ($validUntil === null) {
            return null;
        }

        $timezone = (string) config('app.display_timezone', config('app.timezone'));
        $lastActiveDay = Carbon::parse($validUntil)->setTimezone($timezone)->subSecond();
        $startYear = $lastActiveDay->month >= 9 ? $lastActiveDay->year : $lastActiveDay->year - 1;

        return [$startYear, $startYear + 1];
    }

    /** @param array{int, int} $season */
    private function seasonStart(array $season): Carbon
    {
        $timezone = (string) config('app.display_timezone', config('app.timezone'));

        return Carbon::parse("{$season[0]}-09-01 00:00:00", $timezone)->utc();
    }

    private function waiverType(): string
    {
        return (new StudentWaiver)->getMorphClass();
    }

    private function showcaseType(): string
    {
        return (new ShowcaseParticipation)->getMorphClass();
    }
}
