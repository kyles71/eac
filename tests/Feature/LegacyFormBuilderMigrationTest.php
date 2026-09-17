<?php

declare(strict_types=1);

use App\Forms\DefaultFormDefinitions;
use App\Forms\Eac\EacFormContentProvider;
use App\Forms\Migration\LegacyCutoverSnapshot;
use App\Forms\Migration\LegacyFormMigration;
use App\Forms\Migration\LegacyStudentWaiverContract;
use App\Models\Course;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\FormResponse;
use App\Models\LegalDocument;
use App\Models\ShowcaseParticipation;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Support\LegalDocuments\HealthSafetyPolicy;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Kyle\FilamentFormBuilder\Enums\FormResponseStatus;
use Kyle\FilamentFormBuilder\Enums\FormValidityMode;
use Kyle\FilamentFormBuilder\Enums\FormVersionStatus;
use Kyle\FilamentFormBuilder\Support\FormSchemaDocument;
use Kyle\FilamentFormBuilder\Support\FormTableRegistry;

beforeEach(function (): void {
    $manifestPath = app(LegacyCutoverSnapshot::class)->manifestPath();
    $this->legacyCutoverManifestBackup = File::isFile($manifestPath)
        ? File::get($manifestPath)
        : null;
});

afterEach(function (): void {
    Carbon::setTestNow();
    $manifestPath = app(LegacyCutoverSnapshot::class)->manifestPath();

    if (is_string($this->legacyCutoverManifestBackup)) {
        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, $this->legacyCutoverManifestBackup);
        chmod($manifestPath, 0600);
    } else {
        File::delete($manifestPath);
    }

    File::delete(storage_path('app/private/backups/test-form-builder-snapshot.sql'));
    File::delete(storage_path('app/private/backups/pre-form-builder-20260715-120000.sql'));
    File::delete(storage_path('app/private/backups/pre-form-builder-20260715-120000.sqlite'));
    File::delete(storage_path('framework/testing/legacy-form-snapshot-source.sqlite'));
});

it('hashes the approved waiver contract independently of JSON object key order', function (): void {
    $schema = app(DefaultFormDefinitions::class)->medicalWaiver(
        EacFormContentProvider::healthSafetyPolicyReference(1),
        EacFormContentProvider::textMessageUpdatesPolicyReference(2),
    );
    $sortObjectKeys = function (mixed $value) use (&$sortObjectKeys): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(
            fn (mixed $item): mixed => $sortObjectKeys($item),
            $value,
        );
    };
    $mysqlRoundTrippedSchema = $sortObjectKeys($schema);
    $contract = app(LegacyStudentWaiverContract::class);

    expect($contract->hash($schema))
        ->toBe('fb39de33040f862c5f5363234fa1c7761fb0fff555f135c7b843839d3bc4e725')
        ->toBe($contract->hash($mysqlRoundTrippedSchema));

    $contract->assertMatches($mysqlRoundTrippedSchema);
});

it('installs the Designer V2 columns with the form builder schema', function (): void {
    $tables = app(FormTableRegistry::class);
    $versionsTable = $tables->name('versions');
    $responsesTable = $tables->name('responses');
    $hasValidUntilIndex = collect(Schema::getIndexes($responsesTable))
        ->contains(fn (array $index): bool => $index['columns'] === ['valid_until']);

    expect(Schema::hasColumn($versionsTable, 'settings'))->toBeTrue()
        ->and(Schema::hasColumn($responsesTable, 'valid_until'))->toBeTrue()
        ->and($hasValidUntilIndex)->toBeTrue();
});

it('performs the production-shaped waiver cutover once and creates clean defaults', function (): void {
    Carbon::setTestNow('2026-07-15 12:00:00 UTC');
    $fixture = createProductionLegacyFormFixture();
    $service = app(LegacyFormMigration::class);
    $report = $service->preflight();

    expect($this->artisan('forms:legacy-cutover-required --quiet'))->assertSuccessful();

    expect($report['required'])->toBeTrue()
        ->and($report['source_present'])->toBeTrue()
        ->and($report['counts']['forms'])->toBe(3)
        ->and($report['counts']['form_users'])->toBe(3)
        ->and($report['source_manifest'])->toHaveKeys([
            'metadata', 'assignments', 'responses', 'waiver_answers', 'emergency_contacts', 'course_forms',
        ])
        ->and($report['source_fingerprint'])->toHaveLength(64)
        ->and($report['broadened_phone_values'])->toContain('+1 313-555-0100 ext. 42');

    recordLegacyCutoverTestSnapshot($report);
    $migration = require database_path('migrations/'.LegacyFormMigration::MigrationName.'.php');
    expect($migration)->toBeInstanceOf(Migration::class);
    $migration->up();

    $tables = app(FormTableRegistry::class);
    $waiver = Form::query()->where('key', 'student-waiver')->firstOrFail();
    $version = $waiver->versions()->sole();
    $firstAssignment = FormAssignment::query()->whereMorphedTo('subject', $fixture['first_student'])->firstOrFail();
    $pendingAssignment = FormAssignment::query()->whereMorphedTo('subject', $fixture['second_student'])->firstOrFail();
    $firstResponse = FormResponse::query()->findOrFail(1001);
    $revision = FormResponse::query()->findOrFail(1002);
    $pending = FormResponse::query()->findOrFail(1003);
    $courseFormForeignKey = collect(Schema::getForeignKeys('course_forms'))
        ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['form_id']);

    expect(DB::table('forms')->count())->toBe(3)
        ->and(DB::table('form_users')->count())->toBe(3)
        ->and(DB::table('student_waivers')->count())->toBe(3)
        ->and(DB::table('emergency_contacts')->count())->toBe(4)
        ->and(Form::query()->count())->toBe(1)
        ->and($waiver->active_version_id)->toBe($version->id)
        ->and($version->status)->toBe(FormVersionStatus::Published)
        ->and($version->version)->toBe(1)
        ->and($version->version_key)->toBe('2025-2026')
        ->and($version->schema['schema_version'])->toBe(FormSchemaDocument::Version)
        ->and($version->versionSettings()->validityMode)->toBe(FormValidityMode::VersionEnd)
        ->and(FormAssignment::query()->count())->toBe(2)
        ->and(FormResponse::query()->count())->toBe(3)
        ->and($firstAssignment->form_version_id)->toBe($version->id)
        ->and($firstAssignment->respondent?->is($fixture['current_first_user']))->toBeTrue()
        ->and($pendingAssignment->respondent?->is($fixture['second_student']->user))->toBeTrue()
        ->and($firstResponse->submittedBy?->is($fixture['historical_user']))->toBeTrue()
        ->and($firstResponse->valid_until?->equalTo($version->activation_ends_at))->toBeTrue()
        ->and($revision->submittedBy?->is($fixture['current_first_user']))->toBeTrue()
        ->and($revision->valid_until?->equalTo($version->activation_ends_at))->toBeTrue()
        ->and($revision->revision_of_id)->toBe(1001)
        ->and($revision->projection?->is($fixture['second_waiver']))->toBeTrue()
        ->and($revision->response_state['answers'][DefaultFormDefinitions::MedicalConditions])->toBe('Updated asthma')
        ->and($revision->response_state['answers'][DefaultFormDefinitions::EmergencyContacts][0]['phone_number'])->toBe('+1 313-555-0100 ext. 42')
        ->and($pending->status)->toBe(FormResponseStatus::Draft)
        ->and($pending->valid_until)->toBeNull()
        ->and($pending->form_version_id)->toBe($version->id)
        ->and(DB::table($tables->name('answer_groups'))->count())->toBe(4)
        ->and(DB::table($tables->name('answers'))->count())->toBe(59)
        ->and(DB::table('course_forms')->count())->toBe(1)
        ->and((int) DB::table('course_forms')->value('form_id'))->toBe($waiver->id)
        ->and($courseFormForeignKey['foreign_table'] ?? null)->toBe($tables->name('forms'));

    $schema = $version->schema;
    expect(json_encode($schema, JSON_THROW_ON_ERROR))
        ->toContain(EacFormContentProvider::healthSafetyPolicyReference($fixture['health']->versions()->where('version', 1)->sole()))
        ->toContain(EacFormContentProvider::textMessageUpdatesPolicyReference($fixture['text']->versions()->where('version', 1)->sole()));

    DB::table('migrations')->insert([
        'migration' => LegacyFormMigration::MigrationName,
        'batch' => (int) DB::table('migrations')->max('batch') + 1,
    ]);
    $this->artisan('forms:ensure-defaults')->assertSuccessful();

    $showcase = Form::query()->where('key', 'showcase-participation')->firstOrFail();
    expect($waiver->refresh()->versions()->count())->toBe(2)
        ->and($showcase->active_version_id)->toBeNull()
        ->and($showcase->versions()->where('status', FormVersionStatus::Draft)->count())->toBe(1)
        ->and($showcase->assignments()->count())->toBe(0)
        ->and(DB::table('course_forms')->where('form_id', $showcase->id)->count())->toBe(0);

    $postCutoverForm = Form::factory()->create(['key' => 'post-cutover-form']);
    $migration->up();

    expect($postCutoverForm->fresh())->not->toBeNull()
        ->and(FormResponse::query()->count())->toBe(3)
        ->and($this->artisan('forms:legacy-cutover-required --quiet'))->assertFailed()
        ->and($this->artisan('forms:legacy-preflight'))->assertSuccessful()
        ->and($this->artisan('forms:legacy-snapshot'))->assertSuccessful()
        ->and(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Restore the mandatory pre-deployment database snapshot');
});

it('migrates an unfilled legacy assignment without emergency contacts as an empty draft', function (): void {
    Carbon::setTestNow('2026-07-15 12:00:00 UTC');
    createProductionLegacyFormFixture();
    DB::table('emergency_contacts')->where('student_waiver_id', 503)->delete();
    $service = app(LegacyFormMigration::class);
    $report = $service->preflight();

    recordLegacyCutoverTestSnapshot($report);
    $service->migrate();

    $pending = FormResponse::query()->findOrFail(1003);

    expect($pending->status)->toBe(FormResponseStatus::Draft)
        ->and($pending->answerGroups()->count())->toBe(0);
});

it('rejects a submitted legacy assignment without emergency contacts', function (): void {
    Carbon::setTestNow('2026-07-15 12:00:00 UTC');
    createProductionLegacyFormFixture();
    DB::table('emergency_contacts')->where('student_waiver_id', 501)->delete();

    expect(fn () => app(LegacyFormMigration::class)->preflight())
        ->toThrow(RuntimeException::class, 'Legacy assignment [1001] has no emergency contact.');
});

it('aborts before writes for multiple waiver generations and unexpected showcase data', function (): void {
    Carbon::setTestNow('2026-07-15 12:00:00 UTC');
    createProductionLegacyFormFixture();
    $waiverType = (new StudentWaiver)->getMorphClass();
    DB::table('forms')->insert([
        'id' => 102,
        'name' => 'Unexpected waiver generation',
        'form_type' => $waiverType,
        'can_update' => true,
        'valid_until' => '2027-09-01 04:00:00',
        'created_at' => '2026-06-01 12:00:00',
        'updated_at' => '2026-06-01 12:00:00',
    ]);

    expect(fn () => app(LegacyFormMigration::class)->preflight())
        ->toThrow(RuntimeException::class, 'exactly one student-waiver form');

    DB::table('forms')->where('id', 102)->delete();
    DB::table('showcase_participations')->insert([
        'id' => 601,
        'is_participating' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(LegacyFormMigration::class)->preflight())
        ->toThrow(RuntimeException::class, 'Unexpected legacy showcase projections');

    expect(Form::query()->count())->toBe(0)
        ->and(FormResponse::query()->count())->toBe(0);
});

it('discards only inert legacy showcase assignments and projections', function (): void {
    Carbon::setTestNow('2026-07-15 12:00:00 UTC');
    $fixture = createProductionLegacyFormFixture();
    $showcase = ShowcaseParticipation::factory()->create(['is_participating' => false]);

    DB::table('form_users')->insert([
        'id' => 1004,
        'form_id' => 201,
        'user_id' => $fixture['second_student']->user_id,
        'student_id' => $fixture['second_student']->id,
        'responseable_type' => $showcase->getMorphClass(),
        'responseable_id' => $showcase->id,
        'signature' => null,
        'date_signed' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(LegacyFormMigration::class);
    $report = $service->preflight();
    recordLegacyCutoverTestSnapshot($report);
    $service->migrate();

    expect(Form::query()->where('key', 'student-waiver')->count())->toBe(1)
        ->and(Form::query()->where('key', 'showcase-participation')->count())->toBe(0)
        ->and(FormAssignment::query()->count())->toBe(2)
        ->and(FormResponse::query()->count())->toBe(3)
        ->and(DB::table('form_users')->where('form_id', 201)->count())->toBe(1)
        ->and(DB::table('showcase_participations')->where('id', $showcase->id)->exists())->toBeTrue()
        ->and(DB::table('course_forms')->where('form_id', 201)->exists())->toBeFalse();
});

it('normalizes one active legacy waiver to the current academic year', function (): void {
    Carbon::setTestNow('2026-07-15 12:00:00 UTC');
    createProductionLegacyFormFixture();
    $waiverType = (new StudentWaiver)->getMorphClass();
    DB::table('forms')->where('form_type', $waiverType)->update(['valid_until' => null]);

    expect(app(LegacyFormMigration::class)->preflight()['source_present'])->toBeTrue();

    DB::table('forms')->where('form_type', $waiverType)->update(['valid_until' => '2026-07-15 11:59:59']);

    expect(fn () => app(LegacyFormMigration::class)->preflight())
        ->toThrow(RuntimeException::class, 'not currently active');

    DB::table('forms')->where('form_type', $waiverType)->update(['valid_until' => '2026-08-01 04:00:00']);
    $service = app(LegacyFormMigration::class);
    $report = $service->preflight();
    recordLegacyCutoverTestSnapshot($report);
    $service->migrate();

    $version = Form::query()->where('key', 'student-waiver')->firstOrFail()->versions()->sole();

    expect($version->version_key)->toBe('2025-2026')
        ->and($version->activation_starts_at?->toDateTimeString())->toBe('2025-09-01 04:00:00')
        ->and($version->activation_ends_at?->toDateTimeString())->toBe('2026-09-01 04:00:00');
});

it('accepts an absent destination schema before migrations and rejects a partial installation', function (): void {
    Carbon::setTestNow('2026-07-15 12:00:00 UTC');
    createProductionLegacyFormFixture();
    $destinationTables = collect(['forms', 'versions', 'fields', 'assignments', 'responses', 'answer_groups', 'answers'])
        ->map(fn (string $key): string => app(FormTableRegistry::class)->name($key));
    $destinationState = 'absent';
    $schemaBuilder = DB::connection()->getSchemaBuilder();

    Schema::shouldReceive('hasTable')
        ->andReturnUsing(function (string $table) use ($destinationTables, &$destinationState, $schemaBuilder): bool {
            if (! $destinationTables->contains($table)) {
                return $schemaBuilder->hasTable($table);
            }

            return $destinationState === 'partial' && $table === $destinationTables->first();
        });

    $report = app(LegacyFormMigration::class)->preflight();

    expect($report['required'])->toBeTrue()
        ->and($report['source_present'])->toBeTrue()
        ->and($report['source_fingerprint'])->toHaveLength(64);

    $destinationState = 'partial';

    expect(fn () => app(LegacyFormMigration::class)->preflight())
        ->toThrow(RuntimeException::class, 'Destination package tables are only partially installed');
});

it('requires an unchanged fresh snapshot before writing destination data', function (): void {
    Carbon::setTestNow('2026-07-15 12:00:00 UTC');
    createProductionLegacyFormFixture();
    $service = app(LegacyFormMigration::class);
    $report = $service->preflight();
    recordLegacyCutoverTestSnapshot($report);
    DB::table('form_users')->where('id', 1001)->update(['signature' => 'Changed after snapshot']);

    expect(fn () => $service->migrate())
        ->toThrow(RuntimeException::class, 'source data changed after the snapshot');

    expect(Form::query()->count())->toBe(0)
        ->and(FormResponse::query()->count())->toBe(0);
});

it('creates a private checksummed cutover snapshot manifest', function (): void {
    Carbon::setTestNow('2026-07-15 12:00:00 UTC');
    createProductionLegacyFormFixture();
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $sourcePath = storage_path('framework/testing/legacy-form-snapshot-source.sqlite');
        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, 'non-empty sqlite snapshot source');
        config(['database.connections.sqlite.database' => $sourcePath]);
    }

    $this->artisan('forms:legacy-snapshot')->assertSuccessful();

    $snapshot = app(LegacyCutoverSnapshot::class);
    $manifest = json_decode(File::get($snapshot->manifestPath()), true, flags: JSON_THROW_ON_ERROR);
    $snapshotExtension = $driver === 'sqlite' ? 'sqlite' : 'sql';
    $snapshotPath = storage_path("app/private/backups/pre-form-builder-20260715-120000.{$snapshotExtension}");

    expect($manifest)->toBeArray()
        ->and($manifest['snapshot_path'])->toBe($snapshotPath)
        ->and($manifest['snapshot_sha256'])->toBe(hash_file('sha256', $snapshotPath))
        ->and($manifest['source_fingerprint'])->toBe(app(LegacyFormMigration::class)->preflight()['source_fingerprint'])
        ->and(fileperms($snapshotPath) & 0777)->toBe(0600)
        ->and(fileperms($snapshot->manifestPath()) & 0777)->toBe(0600);
});

/**
 * @return array{historical_user: User, current_first_user: User, first_student: Student, second_student: Student, second_waiver: StudentWaiver, health: LegalDocument, text: LegalDocument, course: Course}
 */
function createProductionLegacyFormFixture(): array
{
    createProductionLegacyFormTables();
    DB::table('migrations')->where('migration', LegacyFormMigration::MigrationName)->delete();
    $health = LegalDocument::factory()->create(['key' => HealthSafetyPolicy::KEY]);
    $health->publishVersion('Initial Health & Safety', '<p>Initial health policy</p>');
    $health->publishVersion('Later Health & Safety', '<p>Later health policy</p>');
    $text = LegalDocument::factory()->create(['key' => TextMessageUpdatesPolicy::KEY]);
    $text->publishVersion('Initial Text Updates', '<p>Initial text policy</p>');
    $text->publishVersion('Later Text Updates', '<p>Later text policy</p>');
    $historicalUser = User::factory()->create();
    $currentFirstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $firstStudent = Student::factory()->create(['user_id' => $currentFirstUser->id]);
    $secondStudent = Student::factory()->create(['user_id' => $secondUser->id]);
    $course = Course::factory()->create();
    $createdAt = '2025-09-01 04:00:00';
    $waiverType = (new StudentWaiver)->getMorphClass();
    $showcaseType = (new ShowcaseParticipation)->getMorphClass();

    DB::table('forms')->insert([
        ['id' => 101, 'name' => 'Current Student Waiver', 'form_type' => $waiverType, 'can_update' => true, 'valid_until' => '2026-09-01 04:00:00', 'created_at' => $createdAt, 'updated_at' => $createdAt],
        ['id' => 201, 'name' => 'Unused Spring Showcase', 'form_type' => $showcaseType, 'can_update' => false, 'valid_until' => '2026-06-01 04:00:00', 'created_at' => $createdAt, 'updated_at' => $createdAt],
        ['id' => 202, 'name' => 'Unused Winter Showcase', 'form_type' => $showcaseType, 'can_update' => false, 'valid_until' => '2027-02-01 05:00:00', 'created_at' => $createdAt, 'updated_at' => $createdAt],
    ]);
    DB::table('student_waivers')->insert([
        productionLegacyWaiverRow(501, 'Asthma', '2025-10-01 12:00:00'),
        productionLegacyWaiverRow(502, 'Updated asthma', '2026-01-01 12:00:00'),
        productionLegacyWaiverRow(503, 'N/A', '2026-02-01 12:00:00'),
    ]);
    DB::table('emergency_contacts')->insert([
        productionLegacyContactRow(701, 501, 'First Contact', '(313) 555-0100', '2025-10-01 12:00:00'),
        productionLegacyContactRow(702, 502, 'Second Contact', '+1 313-555-0100 ext. 42', '2026-01-01 12:00:00'),
        productionLegacyContactRow(703, 503, 'Pending Contact', '(313) 555-0102', '2026-02-01 12:00:00'),
        productionLegacyContactRow(704, 501, 'Additional Contact', '(313) 555-0103', '2025-10-01 12:05:00'),
    ]);
    DB::table('form_users')->insert([
        productionLegacyFormUserRow(1001, $historicalUser, $firstStudent, 501, 'First Signature', '2025-10-01', '2025-10-01 12:00:00'),
        productionLegacyFormUserRow(1002, $currentFirstUser, $firstStudent, 502, 'Second Signature', '2026-01-01', '2026-01-01 12:00:00'),
        productionLegacyFormUserRow(1003, $secondUser, $secondStudent, 503, null, null, '2026-02-01 12:00:00'),
    ]);
    DB::table('course_forms')->insert([
        ['id' => 801, 'course_id' => $course->id, 'form_id' => 101, 'created_at' => $createdAt, 'updated_at' => $createdAt],
        ['id' => 802, 'course_id' => $course->id, 'form_id' => 201, 'created_at' => $createdAt, 'updated_at' => $createdAt],
    ]);

    return [
        'historical_user' => $historicalUser,
        'current_first_user' => $currentFirstUser,
        'first_student' => $firstStudent,
        'second_student' => $secondStudent,
        'second_waiver' => StudentWaiver::query()->findOrFail(502),
        'health' => $health,
        'text' => $text,
        'course' => $course,
    ];
}

function createProductionLegacyFormTables(): void
{
    Schema::dropIfExists('course_forms');
    Schema::dropIfExists('form_users');
    Schema::dropIfExists('forms');
    Schema::create('forms', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('form_type');
        $table->boolean('can_update')->default(true);
        $table->dateTime('valid_until')->nullable();
        $table->timestamps();
    });
    Schema::create('form_users', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
        $table->nullableMorphs('responseable');
        $table->string('signature')->nullable();
        $table->date('date_signed')->nullable();
        $table->timestamps();
    });
    Schema::create('course_forms', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
        $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
        $table->timestamps();
        $table->unique(['course_id', 'form_id']);
    });
}

/** @return array<string, mixed> */
function productionLegacyWaiverRow(int $id, string $medicalConditions, string $timestamp): array
{
    return [
        'id' => $id,
        'medical_conditions' => $medicalConditions,
        'allergies' => 'Peanuts',
        'student_home_address' => '123 Main St',
        'signer_relationship' => 'Legal Guardian',
        'past_injuries' => 'N/A',
        'medications' => 'Inhaler',
        'medical_release_consent' => true,
        'behavioral_notes' => null,
        'medical_release_signed_on' => mb_substr($timestamp, 0, 10),
        'health_safety_policy_consent' => true,
        'health_safety_policy_signed_on' => mb_substr($timestamp, 0, 10),
        'media_release_consent' => false,
        'media_release_signed_on' => mb_substr($timestamp, 0, 10),
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];
}

/** @return array<string, mixed> */
function productionLegacyContactRow(int $id, int $waiverId, string $name, string $phone, string $timestamp): array
{
    return [
        'id' => $id,
        'student_waiver_id' => $waiverId,
        'name' => $name,
        'relationship' => 'Guardian',
        'phone_number' => $phone,
        'wants_text_updates' => true,
        'email' => "contact-{$id}@example.test",
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];
}

/** @return array<string, mixed> */
function productionLegacyFormUserRow(
    int $id,
    User $user,
    Student $student,
    int $waiverId,
    ?string $signature,
    ?string $signedOn,
    string $timestamp,
): array {
    return [
        'id' => $id,
        'form_id' => 101,
        'user_id' => $user->id,
        'student_id' => $student->id,
        'responseable_type' => (new StudentWaiver)->getMorphClass(),
        'responseable_id' => $waiverId,
        'signature' => $signature,
        'date_signed' => $signedOn,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];
}

/** @param array{source_fingerprint: string, source_manifest: array<string, string>} $report */
function recordLegacyCutoverTestSnapshot(array $report): void
{
    $path = storage_path('app/private/backups/test-form-builder-snapshot.sql');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'test database snapshot');
    app(LegacyCutoverSnapshot::class)->record($path, $report['source_fingerprint'], $report['source_manifest']);
}
