<?php

declare(strict_types=1);

namespace App\Forms\Migration;

use App\Forms\DefaultFormDefinitions;
use App\Forms\Eac\EacFormContentProvider;
use App\Models\LegalDocumentVersion;
use App\Models\ShowcaseParticipation;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Support\LegalDocuments\HealthSafetyPolicy;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kyle\FilamentFormBuilder\Enums\FormAnswerType;
use Kyle\FilamentFormBuilder\Enums\FormResponseStatus;
use Kyle\FilamentFormBuilder\Enums\FormUpdateStrategy;
use Kyle\FilamentFormBuilder\Enums\FormVersionStatus;
use Kyle\FilamentFormBuilder\Support\FormDefinition;
use Kyle\FilamentFormBuilder\Support\FormSchemaDocument;
use Kyle\FilamentFormBuilder\Support\FormTableRegistry;
use Kyle\FilamentFormBuilder\Support\FormVersionSettings;
use RuntimeException;

final readonly class LegacyFormMigration
{
    public const string MigrationName = '2026_07_16_025913_migrate_legacy_form_builder_data_to_prefixed_tables';

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
        private FormDefinition $formDefinition,
        private FormTableRegistry $tables,
        private LegacyCutoverSnapshot $snapshot,
        private LegacyStudentWaiverContract $contract,
    ) {}

    public function isRequired(): bool
    {
        return ! Schema::hasTable('migrations')
            || ! DB::table('migrations')->where('migration', self::MigrationName)->exists();
    }

    /**
     * @return array{required: bool, source_present: bool, counts: array<string, int>, broadened_phone_values: list<string>, source_manifest: array<string, string>, source_fingerprint: string}
     */
    public function preflight(): array
    {
        if (! $this->isRequired()) {
            return $this->emptyReport(required: false);
        }

        if (! Schema::hasTable('forms')) {
            return $this->emptyReport(required: true);
        }

        $this->assertSourceTablesExist();
        $waiver = $this->waiverForm();
        $this->assertLegacyShape($waiver);
        [$healthPolicy, $textPolicy] = $this->initialPolicyVersions();
        $blueprint = $this->definitions->studentWaiverBlueprint(
            at: now(),
            healthSafetyPolicyReference: EacFormContentProvider::healthSafetyPolicyReference($healthPolicy),
            textMessageUpdatesPolicyReference: EacFormContentProvider::textMessageUpdatesPolicyReference($textPolicy),
        );
        $this->formDefinition->validate($blueprint->schema);
        $this->contract->assertMatches($blueprint->schema);
        $this->assertDestinationEmpty();
        $sourceManifest = $this->sourceManifestFor($waiver, $healthPolicy, $textPolicy);

        return [
            'required' => true,
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
            'source_manifest' => $sourceManifest,
            'source_fingerprint' => $this->fingerprint($sourceManifest),
        ];
    }

    public function migrate(): void
    {
        $report = $this->preflight();

        if (! $report['required'] || ! $report['source_present']) {
            return;
        }

        $snapshotPath = $this->snapshot->assertFresh($report['source_fingerprint']);
        $waiver = $this->waiverForm();
        [$healthPolicy, $textPolicy] = $this->initialPolicyVersions();
        $blueprint = $this->definitions->studentWaiverBlueprint(
            at: now(),
            healthSafetyPolicyReference: EacFormContentProvider::healthSafetyPolicyReference($healthPolicy),
            textMessageUpdatesPolicyReference: EacFormContentProvider::textMessageUpdatesPolicyReference($textPolicy),
        );

        DB::transaction(function () use ($blueprint, $waiver): void {
            $this->insertFormAndVersion($waiver, $blueprint);
            $this->insertFields((int) $waiver->id, $blueprint->schema);
            $this->insertAssignmentsResponsesAndAnswers($waiver, $blueprint->deactivatesAt);
            DB::table($this->tables->name('forms'))
                ->where('id', $waiver->id)
                ->update(['active_version_id' => $waiver->id]);
        });

        $this->remapWaiverCourseForms($waiver);
        $this->verifyCutover($report['source_manifest'], $waiver, $healthPolicy, $textPolicy);

        DB::table('migrations')->where('migration', self::MigrationName)->doesntExist()
            ?: throw new RuntimeException("The cutover migration was unexpectedly recorded before verification. Snapshot: {$snapshotPath}");
    }

    /** @return array<string, string> */
    public function sourceManifest(): array
    {
        $waiver = $this->waiverForm();
        [$healthPolicy, $textPolicy] = $this->initialPolicyVersions();

        return $this->sourceManifestFor($waiver, $healthPolicy, $textPolicy);
    }

    public function sourceFingerprint(): string
    {
        return $this->fingerprint($this->sourceManifest());
    }

    /** @return array{required: bool, source_present: bool, counts: array<string, int>, broadened_phone_values: list<string>, source_manifest: array<string, string>, source_fingerprint: string} */
    private function emptyReport(bool $required): array
    {
        return [
            'required' => $required,
            'source_present' => false,
            'counts' => [],
            'broadened_phone_values' => [],
            'source_manifest' => [],
            'source_fingerprint' => '',
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

    private function waiverForm(): object
    {
        $waivers = DB::table('forms')->where('form_type', $this->waiverType())->orderBy('id')->get();

        if ($waivers->count() !== 1) {
            throw new RuntimeException("Legacy cutover requires exactly one student-waiver form; found {$waivers->count()}.");
        }

        return $waivers->first();
    }

    private function assertLegacyShape(object $waiver): void
    {
        $knownTypes = [$this->waiverType(), $this->showcaseType()];
        $unknown = DB::table('forms')->whereNotIn('form_type', $knownTypes)->first();

        if ($unknown !== null) {
            throw new RuntimeException("Legacy form [{$unknown->id}] has unknown type [{$unknown->form_type}].");
        }

        if ($waiver->valid_until !== null && now()->greaterThanOrEqualTo($waiver->valid_until)) {
            throw new RuntimeException('The only legacy student-waiver form is not currently active.');
        }

        $showcaseIds = DB::table('forms')->where('form_type', $this->showcaseType())->pluck('id');
        $this->assertShowcaseDataIsDisposable($showcaseIds);

        $courseForms = DB::table('course_forms')->orderBy('id')->get();
        $formIds = DB::table('forms')->pluck('id');

        foreach ($courseForms as $courseForm) {
            if (! $formIds->contains($courseForm->form_id)) {
                throw new RuntimeException("Legacy course form [{$courseForm->id}] references a missing form.");
            }

            if (! DB::table('courses')->where('id', $courseForm->course_id)->exists()) {
                throw new RuntimeException("Legacy course form [{$courseForm->id}] references a missing course.");
            }
        }

        if (! $courseForms->contains(fn (object $row): bool => (int) $row->form_id === (int) $waiver->id)) {
            throw new RuntimeException('The legacy student-waiver form has no valid course reference.');
        }

        foreach (DB::table('form_users')->where('form_id', $waiver->id)->orderBy('id')->get() as $formUser) {
            if (! DB::table('users')->where('id', $formUser->user_id)->exists()) {
                throw new RuntimeException("Legacy assignment [{$formUser->id}] references a missing submitting user.");
            }

            $student = DB::table('students')->where('id', $formUser->student_id)->first();

            if ($student === null || $student->user_id === null || ! DB::table('users')->where('id', $student->user_id)->exists()) {
                throw new RuntimeException("Legacy assignment [{$formUser->id}] does not have a student linked to a valid current user.");
            }

            if ($formUser->responseable_type !== $this->waiverType()
                || $formUser->responseable_id === null
                || ! DB::table('student_waivers')->where('id', $formUser->responseable_id)->exists()) {
                throw new RuntimeException("Legacy assignment [{$formUser->id}] does not reference a valid student-waiver projection.");
            }

            if (($formUser->signature === null) !== ($formUser->date_signed === null)) {
                throw new RuntimeException("Legacy assignment [{$formUser->id}] has an incomplete signature.");
            }

            if ($this->isSubmitted($formUser)
                && ! DB::table('emergency_contacts')->where('student_waiver_id', $formUser->responseable_id)->exists()) {
                throw new RuntimeException("Legacy assignment [{$formUser->id}] has no emergency contact.");
            }
        }

        $invalidContact = DB::table('emergency_contacts')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('name')
                ->orWhereNull('relationship')
                ->orWhereNull('phone_number')
                ->orWhereNull('email')
                ->orWhereNull('wants_text_updates'))
            ->first();

        if ($invalidContact !== null) {
            throw new RuntimeException("Legacy emergency contact [{$invalidContact->id}] violates the approved waiver contract.");
        }
    }

    /** @param Collection<int, int> $showcaseIds */
    private function assertShowcaseDataIsDisposable(Collection $showcaseIds): void
    {
        $showcaseType = $this->showcaseType();
        $assignments = DB::table('form_users')
            ->whereIn('form_id', $showcaseIds)
            ->orderBy('id')
            ->get();

        if ($assignments->contains(fn (object $assignment): bool => $assignment->signature !== null
            || $assignment->date_signed !== null)) {
            throw new RuntimeException('Unexpected signed legacy showcase assignments exist.');
        }

        if ($assignments->contains(fn (object $assignment): bool => $assignment->responseable_type !== $showcaseType
            || $assignment->responseable_id === null)) {
            throw new RuntimeException('Legacy showcase assignments contain missing or mismatched projections.');
        }

        $projectionIds = $assignments
            ->pluck('responseable_id')
            ->map(fn (mixed $id): int => (int) $id);

        if ($projectionIds->unique()->count() !== $projectionIds->count()) {
            throw new RuntimeException('Legacy showcase assignments share a projection unexpectedly.');
        }

        $projections = DB::table('showcase_participations')
            ->whereIn('id', $projectionIds)
            ->orderBy('id')
            ->get();

        if ($projections->count() !== $projectionIds->count()) {
            throw new RuntimeException('Legacy showcase assignments reference missing projections.');
        }

        if ($projections->contains(fn (object $projection): bool => (bool) $projection->is_participating)) {
            throw new RuntimeException('Unexpected participating legacy showcase responses exist.');
        }

        $unexpectedProjectionExists = $projectionIds->isEmpty()
            ? DB::table('showcase_participations')->exists()
            : DB::table('showcase_participations')->whereNotIn('id', $projectionIds)->exists();

        if ($unexpectedProjectionExists
            || DB::table('form_users')
                ->where('responseable_type', $showcaseType)
                ->whereNotIn('form_id', $showcaseIds)
                ->exists()) {
            throw new RuntimeException('Unexpected legacy showcase projections exist.');
        }
    }

    /** @return array{LegalDocumentVersion, LegalDocumentVersion} */
    private function initialPolicyVersions(): array
    {
        return [
            $this->initialPolicyVersion(HealthSafetyPolicy::KEY, 'Health & Safety'),
            $this->initialPolicyVersion(TextMessageUpdatesPolicy::KEY, 'Text Message Updates'),
        ];
    }

    private function initialPolicyVersion(string $documentKey, string $label): LegalDocumentVersion
    {
        $version = LegalDocumentVersion::query()
            ->where('version', 1)
            ->whereNotNull('published_at')
            ->whereHas('document', fn ($query) => $query->where('key', $documentKey))
            ->first();

        if (! $version instanceof LegalDocumentVersion) {
            throw new RuntimeException("The initial published {$label} Policy version is required for the legacy cutover.");
        }

        return $version;
    }

    private function assertDestinationEmpty(): void
    {
        $tables = array_map(
            fn (string $key): string => $this->tables->name($key),
            ['forms', 'versions', 'fields', 'assignments', 'responses', 'answer_groups', 'answers'],
        );
        $missingTables = array_values(array_filter(
            $tables,
            fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if (count($missingTables) === count($tables)) {
            return;
        }

        if ($missingTables !== []) {
            throw new RuntimeException(
                'Destination package tables are only partially installed; missing ['.implode(', ', $missingTables).'].',
            );
        }

        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("Destination package table [{$table}] already contains pre-cutover data.");
            }
        }
    }

    private function insertFormAndVersion(object $waiver, object $blueprint): void
    {
        DB::table($this->tables->name('forms'))->insert([
            'id' => $waiver->id,
            'key' => 'student-waiver',
            'name' => 'Student Waiver',
            'updates_allowed' => true,
            'update_strategy' => FormUpdateStrategy::Revision->value,
            'active_version_id' => null,
            'owner_type' => null,
            'owner_id' => null,
            'created_at' => $waiver->created_at,
            'updated_at' => $waiver->updated_at,
        ]);
        DB::table($this->tables->name('versions'))->insert([
            'id' => $waiver->id,
            'form_id' => $waiver->id,
            'version' => 1,
            'version_key' => $blueprint->versionKey,
            'label' => $blueprint->versionLabel,
            'status' => FormVersionStatus::Published->value,
            'draft_marker' => null,
            'schema' => json_encode(FormSchemaDocument::normalize($blueprint->schema), JSON_THROW_ON_ERROR),
            'settings' => json_encode(FormVersionSettings::from($blueprint->settings, 'Student Waiver')->toArray(), JSON_THROW_ON_ERROR),
            'requires_signature' => true,
            'require_completed_again' => true,
            'activation_starts_at' => $blueprint->activatesAt,
            'activation_ends_at' => $blueprint->deactivatesAt,
            'activated_at' => $blueprint->activatesAt,
            'deactivated_at' => null,
            'published_by_type' => null,
            'published_by_id' => null,
            'published_at' => $waiver->created_at,
            'created_at' => $waiver->created_at,
            'updated_at' => $waiver->updated_at,
        ]);
    }

    /** @param array<int, array<string, mixed>> $schema */
    private function insertFields(int $formId, array $schema): void
    {
        foreach ($this->formDefinition->fields($schema) as $field) {
            DB::table($this->tables->name('fields'))->insert([
                'form_id' => $formId,
                'key' => $field['key'],
                'answer_type' => $field['answer_type']->value,
                'mapping' => $field['mapping'],
                'label' => $field['label'],
                'block_key' => $field['block_key'],
                'sub_key' => $field['sub_key'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function insertAssignmentsResponsesAndAnswers(object $waiver, ?CarbonInterface $validUntil): void
    {
        $userType = (new User)->getMorphClass();
        $studentType = (new Student)->getMorphClass();
        $rowsByStudent = DB::table('form_users')
            ->where('form_id', $waiver->id)
            ->orderBy('student_id')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get()
            ->groupBy('student_id');
        $fieldIds = DB::table($this->tables->name('fields'))
            ->where('form_id', $waiver->id)
            ->pluck('id', 'key');

        foreach ($rowsByStudent as $studentId => $rows) {
            $student = DB::table('students')->where('id', $studentId)->first();
            $assignmentId = (int) $rows->min('id');
            DB::table($this->tables->name('assignments'))->insert([
                'id' => $assignmentId,
                'form_id' => $waiver->id,
                'form_version_id' => $waiver->id,
                'respondent_type' => $userType,
                'respondent_id' => $student->user_id,
                'subject_type' => $studentType,
                'subject_id' => $studentId,
                'created_at' => $rows->min('created_at'),
                'updated_at' => $rows->max('updated_at'),
            ]);
            $previousSubmittedId = null;

            foreach ($rows as $row) {
                $submitted = $this->isSubmitted($row);
                DB::table($this->tables->name('responses'))->insert([
                    'id' => $row->id,
                    'form_assignment_id' => $assignmentId,
                    'form_version_id' => $waiver->id,
                    'revision_of_id' => $previousSubmittedId,
                    'status' => $submitted ? FormResponseStatus::Submitted->value : FormResponseStatus::Draft->value,
                    'signature' => $row->signature,
                    'date_signed' => $row->date_signed,
                    'projection_type' => $row->responseable_type,
                    'projection_id' => $row->responseable_id,
                    'submitted_by_type' => $userType,
                    'submitted_by_id' => $row->user_id,
                    'submitted_at' => $submitted ? $row->updated_at : null,
                    'valid_until' => $submitted ? $validUntil : null,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
                $this->insertWaiverAnswers((int) $row->id, $fieldIds, (int) $row->responseable_id);
                $this->insertEmergencyContactAnswers((int) $row->id, (int) $waiver->id, $fieldIds, (int) $row->responseable_id);

                if ($submitted) {
                    $previousSubmittedId = (int) $row->id;
                }
            }
        }
    }

    /** @param Collection<string, int> $fieldIds */
    private function insertWaiverAnswers(int $responseId, Collection $fieldIds, int $waiverId): void
    {
        $waiver = DB::table('student_waivers')->where('id', $waiverId)->first();

        foreach ($this->waiverAnswerDefinitions() as $key => [$attribute, $type]) {
            $this->insertAnswer(
                responseId: $responseId,
                fieldId: (int) $fieldIds->get($key),
                type: $type,
                value: $waiver->{$attribute},
                createdAt: $waiver->created_at,
                updatedAt: $waiver->updated_at,
            );
        }
    }

    /** @param Collection<string, int> $fieldIds */
    private function insertEmergencyContactAnswers(
        int $responseId,
        int $formId,
        Collection $fieldIds,
        int $waiverId,
    ): void {
        $types = $this->contactAnswerTypes();

        foreach (DB::table('emergency_contacts')->where('student_waiver_id', $waiverId)->orderBy('id')->get()->values() as $position => $contact) {
            DB::table($this->tables->name('answer_groups'))->insert([
                'id' => $contact->id,
                'form_id' => $formId,
                'form_version_id' => $formId,
                'form_response_id' => $responseId,
                'block_key' => DefaultFormDefinitions::EmergencyContacts,
                'position' => $position,
                'created_at' => $contact->created_at,
                'updated_at' => $contact->updated_at,
            ]);

            foreach ($types as $subKey => $type) {
                $this->insertAnswer(
                    responseId: $responseId,
                    fieldId: (int) $fieldIds->get(DefaultFormDefinitions::EmergencyContacts.'.'.$subKey),
                    type: $type,
                    value: $contact->{$subKey},
                    groupId: (int) $contact->id,
                    createdAt: $contact->created_at,
                    updatedAt: $contact->updated_at,
                );
            }
        }
    }

    private function insertAnswer(
        int $responseId,
        int $fieldId,
        FormAnswerType $type,
        mixed $value,
        ?int $groupId = null,
        mixed $createdAt = null,
        mixed $updatedAt = null,
    ): void {
        if ($fieldId === 0) {
            throw new RuntimeException('The cutover could not resolve a required destination form field.');
        }

        $values = array_fill_keys([
            'value_string', 'value_text', 'value_integer', 'value_decimal',
            'value_boolean', 'value_date', 'value_datetime',
        ], null);
        $values[$this->answerColumn($type)] = $value;
        DB::table($this->tables->name('answers'))->insert([
            'form_response_id' => $responseId,
            'form_field_id' => $fieldId,
            'form_answer_group_id' => $groupId,
            ...$values,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $updatedAt ?? now(),
        ]);
    }

    private function remapWaiverCourseForms(object $waiver): void
    {
        $rows = DB::table('course_forms')
            ->where('form_id', $waiver->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'course_id' => (int) $row->course_id,
                'form_id' => (int) $waiver->id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])
            ->unique(fn (array $row): string => $row['course_id'].':'.$row['form_id'])
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

        if (! collect(Schema::getForeignKeys('course_forms'))->contains(
            fn (array $key): bool => $key['columns'] === ['form_id']
                && $key['foreign_table'] === $this->tables->name('forms'),
        )) {
            Schema::table('course_forms', function (Blueprint $table): void {
                $table->foreign('form_id')
                    ->references('id')
                    ->on($this->tables->name('forms'))
                    ->cascadeOnDelete();
            });
        }
    }

    /** @return array<string, string> */
    private function sourceManifestFor(
        object $waiver,
        LegalDocumentVersion $healthPolicy,
        LegalDocumentVersion $textPolicy,
    ): array {
        return collect($this->sourceManifestData($waiver, $healthPolicy, $textPolicy))
            ->map(fn (array $rows): string => $this->hashRows($rows))
            ->all();
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function sourceManifestData(
        object $waiver,
        LegalDocumentVersion $healthPolicy,
        LegalDocumentVersion $textPolicy,
    ): array {
        $validUntil = $this->definitions->studentWaiverBlueprint(
            at: now(),
            healthSafetyPolicyReference: EacFormContentProvider::healthSafetyPolicyReference($healthPolicy),
            textMessageUpdatesPolicyReference: EacFormContentProvider::textMessageUpdatesPolicyReference($textPolicy),
        )->deactivatesAt;
        $rows = DB::table('form_users')
            ->where('form_id', $waiver->id)
            ->orderBy('student_id')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();
        $assignments = [];
        $responses = [];
        $waiverAnswers = [];
        $contacts = [];

        foreach ($rows->groupBy('student_id') as $studentId => $studentRows) {
            $student = DB::table('students')->where('id', $studentId)->first();
            $assignmentId = (int) $studentRows->min('id');
            $assignments[] = [
                'id' => $assignmentId,
                'subject_id' => (int) $studentId,
                'respondent_id' => (int) $student->user_id,
                'created_at' => $studentRows->min('created_at'),
                'updated_at' => $studentRows->max('updated_at'),
            ];
            $previousSubmittedId = null;

            foreach ($studentRows as $row) {
                $submitted = $this->isSubmitted($row);
                $responses[] = [
                    'id' => (int) $row->id,
                    'assignment_id' => $assignmentId,
                    'revision_of_id' => $previousSubmittedId,
                    'status' => $submitted ? FormResponseStatus::Submitted->value : FormResponseStatus::Draft->value,
                    'signature' => $row->signature,
                    'date_signed' => $row->date_signed,
                    'projection_type' => $row->responseable_type,
                    'projection_id' => (int) $row->responseable_id,
                    'submitted_by_id' => (int) $row->user_id,
                    'submitted_at' => $submitted ? $row->updated_at : null,
                    'valid_until' => $submitted ? $validUntil : null,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
                $projection = DB::table('student_waivers')->where('id', $row->responseable_id)->first();

                foreach ($this->waiverAnswerDefinitions() as $key => [$attribute, $type]) {
                    $waiverAnswers[] = [
                        'response_id' => (int) $row->id,
                        'key' => $key,
                        'type' => $type->value,
                        'value' => $this->typedValue($type, $projection->{$attribute}),
                    ];
                }

                foreach (DB::table('emergency_contacts')->where('student_waiver_id', $row->responseable_id)->orderBy('id')->get()->values() as $position => $contact) {
                    foreach ($this->contactAnswerTypes() as $subKey => $type) {
                        $contacts[] = [
                            'response_id' => (int) $row->id,
                            'group_id' => (int) $contact->id,
                            'position' => $position,
                            'key' => DefaultFormDefinitions::EmergencyContacts.'.'.$subKey,
                            'type' => $type->value,
                            'value' => $this->typedValue($type, $contact->{$subKey}),
                        ];
                    }
                }

                if ($submitted) {
                    $previousSubmittedId = (int) $row->id;
                }
            }
        }

        usort($assignments, fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        usort($responses, fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        usort($waiverAnswers, fn (array $left, array $right): int => [$left['response_id'], $left['key']] <=> [$right['response_id'], $right['key']]);
        $this->sortContactRows($contacts);

        return [
            'metadata' => [$this->expectedMetadata($waiver, $healthPolicy, $textPolicy)],
            'assignments' => $assignments,
            'responses' => $responses,
            'waiver_answers' => $waiverAnswers,
            'emergency_contacts' => $contacts,
            'course_forms' => DB::table('course_forms')
                ->where('form_id', $waiver->id)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'course_id' => (int) $row->course_id,
                    'form_id' => (int) $waiver->id,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])
                ->unique(fn (array $row): string => $row['course_id'].':'.$row['form_id'])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function destinationManifestData(object $waiver): array
    {
        $userType = (new User)->getMorphClass();
        $studentType = (new Student)->getMorphClass();
        $assignments = DB::table($this->tables->name('assignments'))
            ->where('form_id', $waiver->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'subject_id' => $row->subject_type === $studentType ? (int) $row->subject_id : null,
                'respondent_id' => $row->respondent_type === $userType ? (int) $row->respondent_id : null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->all();
        $responses = DB::table($this->tables->name('responses'))
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'assignment_id' => (int) $row->form_assignment_id,
                'revision_of_id' => $row->revision_of_id === null ? null : (int) $row->revision_of_id,
                'status' => $row->status,
                'signature' => $row->signature,
                'date_signed' => $row->date_signed,
                'projection_type' => $row->projection_type,
                'projection_id' => (int) $row->projection_id,
                'submitted_by_id' => $row->submitted_by_type === $userType ? (int) $row->submitted_by_id : null,
                'submitted_at' => $row->submitted_at,
                'valid_until' => $row->valid_until,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->all();
        $answerRows = DB::table($this->tables->name('answers').' as answer')
            ->join($this->tables->name('fields').' as field', 'field.id', '=', 'answer.form_field_id')
            ->orderBy('answer.form_response_id')
            ->orderBy('field.key')
            ->select(['answer.*', 'field.key', 'field.answer_type'])
            ->get();
        $waiverAnswers = $answerRows
            ->whereNull('form_answer_group_id')
            ->map(fn (object $row): array => [
                'response_id' => (int) $row->form_response_id,
                'key' => $row->key,
                'type' => $row->answer_type,
                'value' => $this->typedValue(FormAnswerType::from($row->answer_type), $row->{$this->answerColumn(FormAnswerType::from($row->answer_type))}),
            ])->values()->all();
        $groups = DB::table($this->tables->name('answer_groups'))->pluck('position', 'id');
        $contacts = $answerRows
            ->whereNotNull('form_answer_group_id')
            ->map(fn (object $row): array => [
                'response_id' => (int) $row->form_response_id,
                'group_id' => (int) $row->form_answer_group_id,
                'position' => (int) $groups->get($row->form_answer_group_id),
                'key' => $row->key,
                'type' => $row->answer_type,
                'value' => $this->typedValue(FormAnswerType::from($row->answer_type), $row->{$this->answerColumn(FormAnswerType::from($row->answer_type))}),
            ])->values()->all();
        $this->sortContactRows($contacts);

        return [
            'metadata' => [$this->actualMetadata($waiver)],
            'assignments' => $assignments,
            'responses' => $responses,
            'waiver_answers' => $waiverAnswers,
            'emergency_contacts' => $contacts,
            'course_forms' => DB::table('course_forms')->orderBy('id')->get()->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'course_id' => (int) $row->course_id,
                'form_id' => (int) $row->form_id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->all(),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sortContactRows(array &$rows): void
    {
        usort(
            $rows,
            fn (array $left, array $right): int => [
                $left['response_id'],
                $left['position'],
                $left['key'],
                $left['group_id'],
            ] <=> [
                $right['response_id'],
                $right['position'],
                $right['key'],
                $right['group_id'],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function expectedMetadata(
        object $waiver,
        LegalDocumentVersion $healthPolicy,
        LegalDocumentVersion $textPolicy,
    ): array {
        $blueprint = $this->definitions->studentWaiverBlueprint(
            at: now(),
            healthSafetyPolicyReference: EacFormContentProvider::healthSafetyPolicyReference($healthPolicy),
            textMessageUpdatesPolicyReference: EacFormContentProvider::textMessageUpdatesPolicyReference($textPolicy),
        );

        return [
            'form_id' => (int) $waiver->id,
            'version_id' => (int) $waiver->id,
            'active_version_id' => (int) $waiver->id,
            'version_key' => $blueprint->versionKey,
            'activation_starts_at' => $blueprint->activatesAt,
            'activation_ends_at' => $blueprint->deactivatesAt,
            'health_policy_id' => (int) $healthPolicy->id,
            'text_policy_id' => (int) $textPolicy->id,
            'contract_hash' => $this->contract->hash($blueprint->schema),
            'settings' => FormVersionSettings::from($blueprint->settings, 'Student Waiver')->toArray(),
            'fields' => collect($this->formDefinition->fields($blueprint->schema))->map(fn (array $field): array => [
                'key' => $field['key'],
                'type' => $field['answer_type']->value,
                'mapping' => $field['mapping'],
            ])->sortBy('key')->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function actualMetadata(object $waiver): array
    {
        $form = DB::table($this->tables->name('forms'))->where('id', $waiver->id)->first();
        $version = DB::table($this->tables->name('versions'))->where('id', $waiver->id)->first();
        $schema = FormSchemaDocument::components(json_decode($version->schema, true, flags: JSON_THROW_ON_ERROR));
        $references = $this->policyIdsFromSchema($schema);

        return [
            'form_id' => (int) $form->id,
            'version_id' => (int) $version->id,
            'active_version_id' => (int) $form->active_version_id,
            'version_key' => $version->version_key,
            'activation_starts_at' => $version->activation_starts_at,
            'activation_ends_at' => $version->activation_ends_at,
            'health_policy_id' => $references['health'],
            'text_policy_id' => $references['text'],
            'contract_hash' => $this->contract->hash($schema),
            'settings' => json_decode($version->settings, true, flags: JSON_THROW_ON_ERROR),
            'fields' => DB::table($this->tables->name('fields'))
                ->where('form_id', $waiver->id)
                ->orderBy('key')
                ->get()
                ->map(fn (object $field): array => [
                    'key' => $field->key,
                    'type' => $field->answer_type,
                    'mapping' => $field->mapping,
                ])->all(),
        ];
    }

    /** @param array<int, array<string, mixed>> $schema @return array{health: int|null, text: int|null} */
    private function policyIdsFromSchema(array $schema): array
    {
        $ids = ['health' => null, 'text' => null];

        foreach ($schema as $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if (is_string($data['help_reference'] ?? null)
                && str_starts_with($data['help_reference'], EacFormContentProvider::HealthSafetyPolicyVersionPrefix)) {
                $ids['health'] = (int) str($data['help_reference'])->after(EacFormContentProvider::HealthSafetyPolicyVersionPrefix)->toString();
            }

            if (is_string($data['text_message_policy_reference'] ?? null)
                && str_starts_with($data['text_message_policy_reference'], EacFormContentProvider::TextMessageUpdatesPolicyVersionPrefix)) {
                $ids['text'] = (int) str($data['text_message_policy_reference'])->after(EacFormContentProvider::TextMessageUpdatesPolicyVersionPrefix)->toString();
            }

            if (is_array($data['components'] ?? null)) {
                $nested = $this->policyIdsFromSchema($data['components']);
                $ids['health'] ??= $nested['health'];
                $ids['text'] ??= $nested['text'];
            }
        }

        return $ids;
    }

    /** @param array<string, string> $expected */
    private function verifyCutover(
        array $expected,
        object $waiver,
        LegalDocumentVersion $healthPolicy,
        LegalDocumentVersion $textPolicy,
    ): void {
        $actual = collect($this->destinationManifestData($waiver))
            ->map(fn (array $rows): string => $this->hashRows($rows))
            ->all();

        foreach ($expected as $category => $hash) {
            if (! isset($actual[$category]) || ! hash_equals($hash, $actual[$category])) {
                throw new RuntimeException("Legacy cutover manifest mismatch for [{$category}].");
            }
        }

        if ((int) DB::table($this->tables->name('forms'))->where('id', $waiver->id)->value('active_version_id') !== (int) $waiver->id) {
            throw new RuntimeException('The migrated student-waiver form does not point to its initial active version.');
        }

        $this->assertNoOrphans();
        $this->contract->assertMatches($this->definitions->medicalWaiver(
            EacFormContentProvider::healthSafetyPolicyReference($healthPolicy),
            EacFormContentProvider::textMessageUpdatesPolicyReference($textPolicy),
        ));
    }

    private function assertNoOrphans(): void
    {
        $tables = collect(['forms', 'versions', 'assignments', 'responses', 'answer_groups', 'answers', 'fields'])
            ->mapWithKeys(fn (string $key): array => [$key => $this->tables->name($key)]);
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
            $count = DB::table("{$child} as child")
                ->when($nullable, fn (Builder $query): Builder => $query->whereNotNull("child.{$foreignKey}"))
                ->whereNotExists(fn (Builder $query): Builder => $query
                    ->selectRaw('1')
                    ->from("{$parent} as parent")
                    ->whereColumn('parent.id', "child.{$foreignKey}"))
                ->count();

            if ($count !== 0) {
                throw new RuntimeException("Migrated relationship [{$child}.{$foreignKey}] contains {$count} orphaned rows.");
            }
        }

        $inconsistentAssignments = DB::table($tables['assignments'].' as assignment')
            ->join($tables['versions'].' as version', 'version.id', '=', 'assignment.form_version_id')
            ->whereColumn('assignment.form_id', '!=', 'version.form_id')
            ->count();

        if ($inconsistentAssignments !== 0) {
            throw new RuntimeException('Migrated assignments contain inconsistent form/version pairs.');
        }
    }

    /** @return array<string, array{string, FormAnswerType}> */
    private function waiverAnswerDefinitions(): array
    {
        return [
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
    }

    /** @return array<string, FormAnswerType> */
    private function contactAnswerTypes(): array
    {
        return [
            'name' => FormAnswerType::String,
            'relationship' => FormAnswerType::String,
            'phone_number' => FormAnswerType::String,
            'email' => FormAnswerType::String,
            'wants_text_updates' => FormAnswerType::Boolean,
        ];
    }

    private function answerColumn(FormAnswerType $type): string
    {
        return match ($type) {
            FormAnswerType::String => 'value_string',
            FormAnswerType::Text => 'value_text',
            FormAnswerType::Integer => 'value_integer',
            FormAnswerType::Decimal => 'value_decimal',
            FormAnswerType::Boolean => 'value_boolean',
            FormAnswerType::Date => 'value_date',
            FormAnswerType::DateTime => 'value_datetime',
        };
    }

    private function typedValue(FormAnswerType $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            FormAnswerType::Boolean => (bool) $value,
            FormAnswerType::Integer => (int) $value,
            FormAnswerType::Decimal => (string) $value,
            default => (string) $value,
        };
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function hashRows(array $rows): string
    {
        return hash('sha256', json_encode(
            $this->normalizeValue($rows),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->utc()->format('Y-m-d H:i:s');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);
    }

    /** @param array<string, string> $manifest */
    private function fingerprint(array $manifest): string
    {
        ksort($manifest);

        return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function isSubmitted(object $formUser): bool
    {
        return $formUser->signature !== null && $formUser->date_signed !== null;
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
