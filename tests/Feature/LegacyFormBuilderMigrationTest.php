<?php

declare(strict_types=1);

use App\Forms\Migration\LegacyFormMigration;
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
use Illuminate\Support\Facades\Schema;
use Kyle\FilamentFormBuilder\Support\FormTableRegistry;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('converts the exact origin dev legacy graph without changing its source rows', function (): void {
    Carbon::setTestNow('2026-07-15 12:00:00 UTC');
    $fixture = createOriginDevLegacyFormFixture();
    $migration = require database_path('migrations/2026_07_16_025913_migrate_legacy_form_builder_data_to_prefixed_tables.php');

    expect($migration)->toBeInstanceOf(Migration::class);

    $preflight = app(LegacyFormMigration::class)->preflight();

    expect($preflight['counts']['forms'])->toBe(5)
        ->and($preflight['counts']['form_users'])->toBe(6)
        ->and($preflight['broadened_phone_values'])->toContain('+1 313-555-0100 ext. 42');

    $migration->up();
    $migration->up();

    $tables = app(FormTableRegistry::class);
    $waiver = Form::query()->where('key', 'student-waiver')->firstOrFail();
    $showcase = Form::query()->where('key', 'showcase-participation')->firstOrFail();
    $waiverAssignment = FormAssignment::query()
        ->where('form_id', $waiver->id)
        ->whereMorphedTo('subject', $fixture['first_student'])
        ->firstOrFail();
    $firstResponse = FormResponse::query()->findOrFail(1001);
    $revision = FormResponse::query()->findOrFail(1002);

    expect(DB::table('forms')->count())->toBe(5)
        ->and(DB::table('form_users')->count())->toBe(6)
        ->and(DB::table('student_waivers')->count())->toBe(2)
        ->and(DB::table('showcase_participations')->count())->toBe(2)
        ->and(DB::table('emergency_contacts')->count())->toBe(2)
        ->and(Form::query()->count())->toBe(2)
        ->and($waiver->versions()->count())->toBe(2)
        ->and($showcase->versions()->count())->toBe(2)
        ->and($showcase->versions()->pluck('version_key')->all())->toBe(['legacy-201', 'legacy-202'])
        ->and(FormAssignment::query()->count())->toBe(3)
        ->and(FormResponse::query()->count())->toBe(6)
        ->and($waiverAssignment->form_version_id)->toBe(103)
        ->and($waiverAssignment->respondent?->is($fixture['second_user']))->toBeTrue()
        ->and($firstResponse->submittedBy?->is($fixture['first_user']))->toBeTrue()
        ->and($revision->submittedBy?->is($fixture['second_user']))->toBeTrue()
        ->and($revision->revision_of_id)->toBe(1001)
        ->and($revision->projection?->is($fixture['second_waiver']))->toBeTrue()
        ->and($revision->response_state['answers'][App\Forms\DefaultFormDefinitions::MedicalConditions])->toBe('Updated asthma')
        ->and($revision->response_state['answers'][App\Forms\DefaultFormDefinitions::EmergencyContacts][0]['phone_number'])->toBe('+1 313-555-0100 ext. 42')
        ->and(DB::table($tables->name('answer_groups'))->count())->toBe(2)
        ->and(DB::table($tables->name('answers'))->count())->toBe(38)
        ->and(DB::table('course_forms')->count())->toBe(1)
        ->and(DB::table('course_forms')->value('form_id'))->toBe($waiver->id);

    $verification = app(LegacyFormMigration::class)->verify();

    expect($verification)->toBe([
        'responses' => 6,
        'responseable_projections' => 4,
        'emergency_contacts' => 2,
        'course_forms' => 1,
    ]);

    $formForeignKey = collect(Schema::getForeignKeys('course_forms'))
        ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['form_id']);

    expect($formForeignKey['foreign_table'])->toBe($tables->name('forms'))
        ->and(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Restore the mandatory pre-deployment database snapshot');
});

it('aborts preflight before writes when the destination contains unrelated rows', function (): void {
    createOriginDevLegacyFormFixture();
    $existing = Form::factory()->create(['key' => 'unrelated-destination-form']);
    $tables = app(FormTableRegistry::class);

    expect(fn () => app(LegacyFormMigration::class)->migrate())
        ->toThrow(RuntimeException::class, 'collides with the legacy conversion');

    expect(DB::table($tables->name('forms'))->count())->toBe(1)
        ->and(DB::table($tables->name('forms'))->where('id', $existing->id)->exists())->toBeTrue()
        ->and(DB::table($tables->name('responses'))->count())->toBe(0)
        ->and(DB::table('forms')->count())->toBe(5);
});

/**
 * @return array{first_user: User, second_user: User, first_student: Student, second_student: Student, first_waiver: StudentWaiver, second_waiver: StudentWaiver, course: Course}
 */
function createOriginDevLegacyFormFixture(): array
{
    createOriginDevLegacyFormTables();
    $health = LegalDocument::factory()->create(['key' => HealthSafetyPolicy::KEY]);
    $health->publishVersion('Health & Safety v1', '<p>Current health policy</p>');
    $text = LegalDocument::factory()->create(['key' => TextMessageUpdatesPolicy::KEY]);
    $text->publishVersion('Text Message Updates v1', '<p>Current text policy</p>');
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $firstStudent = Student::factory()->create(['user_id' => $firstUser->id]);
    $secondStudent = Student::factory()->create(['user_id' => $secondUser->id]);
    $course = Course::factory()->create();
    $createdAt = '2025-01-10 12:00:00';
    $waiverType = (new StudentWaiver)->getMorphClass();
    $showcaseType = (new ShowcaseParticipation)->getMorphClass();

    DB::table('forms')->insert([
        ['id' => 101, 'name' => 'Waiver A', 'form_type' => $waiverType, 'can_update' => true, 'valid_until' => '2026-09-01 04:00:00', 'created_at' => $createdAt, 'updated_at' => $createdAt],
        ['id' => 102, 'name' => 'Waiver B', 'form_type' => $waiverType, 'can_update' => true, 'valid_until' => '2026-09-01 04:00:00', 'created_at' => '2025-02-01 12:00:00', 'updated_at' => '2025-02-01 12:00:00'],
        ['id' => 103, 'name' => 'Next Waiver', 'form_type' => $waiverType, 'can_update' => true, 'valid_until' => '2027-09-01 04:00:00', 'created_at' => '2026-06-01 12:00:00', 'updated_at' => '2026-06-01 12:00:00'],
        ['id' => 201, 'name' => 'Spring Showcase', 'form_type' => $showcaseType, 'can_update' => false, 'valid_until' => '2025-06-01 04:00:00', 'created_at' => '2025-03-01 12:00:00', 'updated_at' => '2025-03-01 12:00:00'],
        ['id' => 202, 'name' => 'Winter Showcase', 'form_type' => $showcaseType, 'can_update' => false, 'valid_until' => '2026-02-01 05:00:00', 'created_at' => '2025-11-01 12:00:00', 'updated_at' => '2025-11-01 12:00:00'],
    ]);

    DB::table('student_waivers')->insert([
        legacyWaiverRow(501, 'Asthma', '2025-02-01 12:00:00'),
        legacyWaiverRow(502, 'Updated asthma', '2025-04-01 12:00:00'),
    ]);
    DB::table('showcase_participations')->insert([
        ['id' => 601, 'is_participating' => true, 'created_at' => $createdAt, 'updated_at' => $createdAt],
        ['id' => 602, 'is_participating' => false, 'created_at' => '2025-11-02 12:00:00', 'updated_at' => '2025-11-02 12:00:00'],
    ]);
    DB::table('emergency_contacts')->insert([
        ['id' => 701, 'student_waiver_id' => 501, 'name' => 'First Contact', 'relationship' => 'Parent', 'phone_number' => '(313) 555-0100', 'wants_text_updates' => true, 'email' => 'first@example.test', 'created_at' => $createdAt, 'updated_at' => $createdAt],
        ['id' => 702, 'student_waiver_id' => 502, 'name' => 'Second Contact', 'relationship' => 'Guardian', 'phone_number' => '+1 313-555-0100 ext. 42', 'wants_text_updates' => false, 'email' => null, 'created_at' => '2025-04-01 12:00:00', 'updated_at' => '2025-04-01 12:00:00'],
    ]);
    DB::table('form_users')->insert([
        legacyFormUserRow(1001, 101, $firstUser, $firstStudent, $waiverType, 501, 'First Signature', '2025-02-01', '2025-02-01 12:00:00'),
        legacyFormUserRow(1002, 102, $secondUser, $firstStudent, $waiverType, 502, 'Second Signature', '2025-04-01', '2025-04-01 12:00:00'),
        legacyFormUserRow(1003, 103, $secondUser, $firstStudent, null, null, null, null, '2026-06-01 12:00:00'),
        legacyFormUserRow(1004, 201, $firstUser, $firstStudent, $showcaseType, 601, 'Showcase One', '2025-03-02', '2025-03-02 12:00:00'),
        legacyFormUserRow(1005, 202, $secondUser, $firstStudent, $showcaseType, 602, 'Showcase Two', '2025-11-02', '2025-11-02 12:00:00'),
        legacyFormUserRow(1006, 101, $secondUser, $secondStudent, null, null, 'Historical Signature', '2025-02-02', '2025-02-02 12:00:00'),
    ]);
    DB::table('course_forms')->insert([
        ['id' => 801, 'course_id' => $course->id, 'form_id' => 101, 'created_at' => $createdAt, 'updated_at' => $createdAt],
        ['id' => 802, 'course_id' => $course->id, 'form_id' => 102, 'created_at' => $createdAt, 'updated_at' => $createdAt],
    ]);

    return [
        'first_user' => $firstUser,
        'second_user' => $secondUser,
        'first_student' => $firstStudent,
        'second_student' => $secondStudent,
        'first_waiver' => StudentWaiver::query()->findOrFail(501),
        'second_waiver' => StudentWaiver::query()->findOrFail(502),
        'course' => $course,
    ];
}

function createOriginDevLegacyFormTables(): void
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
        $table->foreignId('student_id')->nullable()->constrained('students')->cascadeOnDelete();
        $table->nullableMorphs('responseable');
        $table->string('signature')->nullable();
        $table->date('date_signed')->nullable();
        $table->timestamps();
        $table->index(['form_id', 'student_id']);
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
function legacyWaiverRow(int $id, string $medicalConditions, string $timestamp): array
{
    return [
        'id' => $id,
        'medical_conditions' => $medicalConditions,
        'allergies' => 'Peanuts',
        'student_home_address' => '123 Main St',
        'signer_relationship' => 'Parent',
        'past_injuries' => null,
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
function legacyFormUserRow(
    int $id,
    int $formId,
    User $user,
    Student $student,
    ?string $projectionType,
    ?int $projectionId,
    ?string $signature,
    ?string $signedOn,
    string $timestamp,
): array {
    return [
        'id' => $id,
        'form_id' => $formId,
        'user_id' => $user->id,
        'student_id' => $student->id,
        'responseable_type' => $projectionType,
        'responseable_id' => $projectionId,
        'signature' => $signature,
        'date_signed' => $signedOn,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];
}
