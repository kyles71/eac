<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Forms\Components\FormVersionPreview;
use App\Filament\Admin\Resources\Forms\Pages\EditFormVersion;
use App\Filament\Admin\Resources\Forms\Pages\ViewForm;
use App\Filament\Shared\Forms\Components\PreviewBuilder;
use App\Forms\DefaultFormDefinitions;
use App\Forms\Eac\Blocks\EmergencyContactsBlock;
use App\Forms\Eac\EacFormContentProvider;
use App\Models\Form;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use App\Models\User;
use App\Support\LegalDocuments\HealthSafetyPolicy;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;
use Database\Seeders\ShowcaseParticipationFormSeeder;
use Database\Seeders\StudentWaiverFormSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire as LivewireSchemaComponent;
use Illuminate\Support\Carbon;
use Kyle\FilamentFormBuilder\Actions\PublishFormVersion;
use Kyle\FilamentFormBuilder\Enums\FormContentSlot;
use Kyle\FilamentFormBuilder\Enums\FormHelpPosition;
use Kyle\FilamentFormBuilder\Enums\FormUpdateStrategy;
use Kyle\FilamentFormBuilder\Enums\FormVersionStatus;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\RelationManagers\VersionsRelationManager;
use Kyle\FilamentFormBuilder\Support\FormContentRegistry;
use Kyle\FilamentFormBuilder\Support\FormVersionComparator;
use Kyle\FilamentFormBuilder\Support\PhoneNumber;

use function Pest\Livewire\livewire;

afterEach(function (): void {
    Carbon::setTestNow();
});

function publishTextMessageUpdatesPolicy(): LegalDocumentVersion
{
    $policy = LegalDocument::factory()->create(['key' => TextMessageUpdatesPolicy::KEY]);

    return $policy->publishVersion('Text Message Updates v1', '<p>Policy</p>');
}

it('installs the seasonal waiver and showcase blueprints idempotently', function (): void {
    Carbon::setTestNow('2026-07-12 12:00:00 UTC');
    $healthSafetyPolicy = LegalDocument::factory()->create(['key' => HealthSafetyPolicy::KEY]);
    $healthSafetyVersion = $healthSafetyPolicy->publishVersion('Health & Safety v1', '<p>First</p>');
    $textUpdatesVersion = publishTextMessageUpdatesPolicy();
    $publisher = User::query()
        ->where('email', config('app.default_user.email'))
        ->firstOrFail();

    $this->seed(StudentWaiverFormSeeder::class);
    $this->seed(ShowcaseParticipationFormSeeder::class);
    $healthSafetyPolicy->publishVersion('Health & Safety v2', '<p>Second</p>');
    $this->seed(StudentWaiverFormSeeder::class);
    $this->seed(ShowcaseParticipationFormSeeder::class);

    $waiver = Form::query()->where('key', 'student-waiver')->firstOrFail();
    $waiverVersion = $waiver->versions()->sole();
    $showcase = Form::query()->where('key', 'showcase-participation')->firstOrFail();
    $showcaseVersion = $showcase->versions()->sole();
    $medicalTreatmentHeading = findSeededFormBlock($waiverVersion->schema, '637a63f9-cf95-4213-a18f-05f7bc472aaa');
    $emergencyContacts = findSeededFormBlock($waiverVersion->schema, DefaultFormDefinitions::EmergencyContacts);
    $signerRelationship = findSeededFormBlock($waiverVersion->schema, DefaultFormDefinitions::SignerRelationship);
    $studentHomeAddress = findSeededFormBlock($waiverVersion->schema, DefaultFormDefinitions::StudentHomeAddress);
    $behavioralNotes = findSeededFormBlock($waiverVersion->schema, DefaultFormDefinitions::BehavioralNotes);
    $medicalReleaseConsent = findSeededFormBlock($waiverVersion->schema, DefaultFormDefinitions::MedicalReleaseConsent);
    $medicalReleaseSignedOn = findSeededFormBlock($waiverVersion->schema, DefaultFormDefinitions::MedicalReleaseSignedOn);
    $healthSafetyConsent = findSeededFormBlock($waiverVersion->schema, DefaultFormDefinitions::HealthSafetyPolicyConsent);
    $healthSafetySignedOn = findSeededFormBlock($waiverVersion->schema, DefaultFormDefinitions::HealthSafetyPolicySignedOn);
    $mediaConsent = findSeededFormBlock($waiverVersion->schema, DefaultFormDefinitions::MediaReleaseConsent);
    $mediaReleaseSignedOn = findSeededFormBlock($waiverVersion->schema, DefaultFormDefinitions::MediaReleaseSignedOn);
    $showcaseParticipation = findSeededFormBlock($showcaseVersion->schema, DefaultFormDefinitions::ShowcaseParticipation);
    $emergencyContactsComponent = app(EmergencyContactsBlock::class)->compile($emergencyContacts['data'], null, false);
    $emergencyContactRules = app(EmergencyContactsBlock::class)->validationRules($emergencyContacts['data']);

    expect(Form::query()->count())->toBe(2)
        ->and($waiver->name)->toBe('Student Waiver')
        ->and($waiver->updates_allowed)->toBeTrue()
        ->and($waiver->update_strategy)->toBe(FormUpdateStrategy::Revision)
        ->and($waiver->active_version_id)->toBe($waiverVersion->id)
        ->and($waiverVersion->version_key)->toBe('2025-2026')
        ->and($waiverVersion->label)->toBe('September 2025 – August 2026')
        ->and($waiverVersion->requires_signature)->toBeTrue()
        ->and($waiverVersion->require_completed_again)->toBeTrue()
        ->and($waiverVersion->activation_starts_at?->setTimezone('America/Detroit')->format('Y-m-d H:i'))->toBe('2025-09-01 00:00')
        ->and($waiverVersion->activation_ends_at?->setTimezone('America/Detroit')->format('Y-m-d H:i'))->toBe('2026-09-01 00:00')
        ->and($waiverVersion->publisher?->is($publisher))->toBeTrue()
        ->and($waiver->versions()->count())->toBe(1)
        ->and($waiver->fields()->whereNotNull('mapping')->count())->toBe(13)
        ->and($waiver->fields()->where('block_key', DefaultFormDefinitions::EmergencyContacts)->count())->toBe(5)
        ->and($emergencyContacts['data']['min_items'])->toBe(1)
        ->and($emergencyContacts['data']['default_items'])->toBe(2)
        ->and($emergencyContacts['data']['text_message_policy_reference'])
        ->toBe(EacFormContentProvider::textMessageUpdatesPolicyReference($textUpdatesVersion))
        ->and($emergencyContactsComponent)->toBeInstanceOf(Repeater::class)
        ->and(app(EmergencyContactsBlock::class)->blockPickerTooltip())->toContain('one or more emergency contacts')
        ->and($emergencyContactsComponent->getMinItems())->toBe(1)
        ->and($emergencyContactsComponent->getDefaultState())->toHaveCount(2)
        ->and(collect($emergencyContactRules['answers.'.DefaultFormDefinitions::EmergencyContacts.'.*.phone_number'])
            ->contains(fn (mixed $rule): bool => $rule instanceof PhoneNumber))->toBeTrue()
        ->and($signerRelationship['data']['column_span'])->toBe(1)
        ->and($studentHomeAddress['data']['column_span'])->toBe(2)
        ->and($studentHomeAddress['data']['help_position'])->toBe(FormHelpPosition::Above->value)
        ->and($behavioralNotes['data']['help'])->toContain('certain challenges may require strategies beyond what we can accommodate')
        ->and($behavioralNotes['data']['help_position'])->toBe(FormHelpPosition::Above->value)
        ->and($medicalReleaseConsent['data']['column_span'])->toBe(1)
        ->and($medicalReleaseConsent['data'])->not->toHaveKey('required')
        ->and($medicalReleaseConsent['data'])->not->toHaveKey('accepted')
        ->and($medicalReleaseSignedOn['data']['column_span'])->toBe(1)
        ->and($medicalReleaseSignedOn['data']['help_position'])->toBe(FormHelpPosition::Above->value)
        ->and($medicalTreatmentHeading['data']['content'])->toBe('<strong>Consent to Medical Treatment</strong>')
        ->and($medicalTreatmentHeading['data']['is_html'])->toBeTrue()
        ->and($healthSafetyConsent['data']['help_reference'])->toBe(EacFormContentProvider::healthSafetyPolicyReference($healthSafetyVersion))
        ->and($healthSafetyConsent['data']['help_position'])->toBe(FormHelpPosition::Below->value)
        ->and($healthSafetySignedOn['data']['column_span'])->toBe(1)
        ->and($mediaConsent['type'])->toBe('boolean_radio')
        ->and($mediaConsent['data']['true_label'])->toBe('I consent')
        ->and($mediaConsent['data']['false_label'])->toBe('I do not consent')
        ->and($mediaConsent['data']['column_span'])->toBe(1)
        ->and($mediaReleaseSignedOn['data']['column_span'])->toBe(1)
        ->and($showcaseParticipation['data']['column_span'])->toBe(1)
        ->and(json_encode($waiverVersion->schema, JSON_THROW_ON_ERROR))->not->toContain('legal-documents/versions')
        ->and($showcase->name)->toBe('Showcase Participation')
        ->and($showcase->updates_allowed)->toBeFalse()
        ->and($showcase->update_strategy)->toBeNull()
        ->and($showcase->active_version_id)->toBeNull()
        ->and($showcaseVersion->status)->toBe(FormVersionStatus::Draft)
        ->and($showcaseVersion->version_key)->toBeNull()
        ->and($showcaseVersion->label)->toContain('Showcase template')
        ->and($showcaseVersion->requires_signature)->toBeTrue()
        ->and($showcase->versions()->count())->toBe(1);
});

it('ensures the current and next waiver seasons and a single showcase template idempotently', function (): void {
    Carbon::setTestNow('2026-07-12 12:00:00 UTC');
    LegalDocument::factory()
        ->create(['key' => HealthSafetyPolicy::KEY])
        ->publishVersion('Health & Safety v1', '<p>Policy</p>');
    publishTextMessageUpdatesPolicy();

    $this->artisan('forms:ensure-defaults')->assertSuccessful();
    $this->artisan('forms:ensure-defaults')->assertSuccessful();

    $waiver = Form::query()->where('key', 'student-waiver')->firstOrFail();
    $showcase = Form::query()->where('key', 'showcase-participation')->firstOrFail();

    expect($waiver->versions()->pluck('version_key')->all())
        ->toEqualCanonicalizing(['2025-2026', '2026-2027'])
        ->and($showcase->active_version_id)->toBeNull()
        ->and($showcase->versions()->where('status', FormVersionStatus::Draft)->count())->toBe(1)
        ->and($showcase->versions()->where('status', FormVersionStatus::Published)->count())->toBe(0);
});

it('pins EAC legal-document links to the selected published version', function (): void {
    Carbon::setTestNow('2026-07-12 12:00:00 UTC');
    $healthSafetyPolicy = LegalDocument::factory()->create(['key' => HealthSafetyPolicy::KEY]);
    $firstVersion = $healthSafetyPolicy->publishVersion('Health & Safety v1', '<p>First</p>');
    $textUpdatesVersion = publishTextMessageUpdatesPolicy();
    $firstReference = EacFormContentProvider::healthSafetyPolicyReference($firstVersion);
    $this->seed(StudentWaiverFormSeeder::class);
    $version = Form::query()->where('key', 'student-waiver')->firstOrFail()->currentVersion;
    $content = app(FormContentRegistry::class);

    expect($content->options(FormContentSlot::QuestionHelp))->toHaveKey(
        $firstReference,
    )->and($content->options(FormContentSlot::Instructions))->toBe([]);
    $firstLink = (string) $content->resolve(
        $firstReference,
        FormContentSlot::QuestionHelp,
        $version,
    );
    $secondVersion = $healthSafetyPolicy->publishVersion('Health & Safety v2', '<p>Second</p>');
    $secondReference = EacFormContentProvider::healthSafetyPolicyReference($secondVersion);
    $stillFirstLink = (string) $content->resolve(
        $firstReference,
        FormContentSlot::QuestionHelp,
        $version,
    );
    $secondLink = (string) $content->resolve($secondReference, FormContentSlot::QuestionHelp, $version);

    expect($firstLink)->toContain(route('legal-documents.versions.show', $firstVersion))
        ->and($stillFirstLink)->toContain(route('legal-documents.versions.show', $firstVersion))
        ->and($stillFirstLink)->not->toContain(route('legal-documents.versions.show', $secondVersion))
        ->and($secondLink)->toContain(route('legal-documents.versions.show', $secondVersion))
        ->and($content->options(FormContentSlot::QuestionHelp))->toHaveKeys([$firstReference, $secondReference]);

    Carbon::setTestNow('2026-09-02 12:00:00 UTC');
    $this->seed(StudentWaiverFormSeeder::class);
    $newVersion = Form::query()
        ->where('key', 'student-waiver')
        ->firstOrFail()
        ->versions()
        ->where('version_key', '2026-2027')
        ->firstOrFail();
    $newConsent = findSeededFormBlock($newVersion->schema, DefaultFormDefinitions::HealthSafetyPolicyConsent);

    expect($newConsent['data']['help_reference'])->toBe($secondReference);

    $textUpdatesHelp = (string) app(EacFormContentProvider::class)->textMessageUpdatesHelp(
        EacFormContentProvider::textMessageUpdatesPolicyReference($textUpdatesVersion),
    );

    expect($textUpdatesHelp)->toContain('Text message updates are only utilized for urgent updates')
        ->and($textUpdatesHelp)->toContain(route('legal-documents.versions.show', $textUpdatesVersion));
});

it('rejects missing or unrelated immutable policy references during publication', function (): void {
    $unrelatedDocument = LegalDocument::factory()->create(['key' => 'unrelated-policy']);
    $unrelatedVersion = $unrelatedDocument->publishVersion('Unrelated v1', '<p>Other</p>');
    $form = Form::factory()->create();
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'requires_signature' => false,
        'schema' => [[
            'type' => 'checkbox',
            'data' => [
                'key' => DefaultFormDefinitions::HealthSafetyPolicyConsent,
                'label' => 'I agree.',
                'help_reference' => EacFormContentProvider::healthSafetyPolicyReference($unrelatedVersion),
            ],
        ]],
    ]);

    expect(fn () => app(PublishFormVersion::class)->handle($version, auth()->user()))
        ->toThrow(InvalidArgumentException::class, 'does not belong to the Health & Safety Policy');

    $schema = $version->schema;
    $schema[0]['data']['help_reference'] = EacFormContentProvider::healthSafetyPolicyReference(999999);
    $version->update(['schema' => $schema]);

    expect(fn () => app(PublishFormVersion::class)->handle($version->refresh(), auth()->user()))
        ->toThrow(InvalidArgumentException::class, 'no longer exists');
});

it('allows an author to select the EAC policy link for question help', function (): void {
    Filament::setCurrentPanel('admin');
    $healthSafetyPolicy = LegalDocument::factory()->create(['key' => HealthSafetyPolicy::KEY]);
    $firstVersion = $healthSafetyPolicy->publishVersion('Health & Safety v1', '<p>First</p>');
    $secondVersion = $healthSafetyPolicy->publishVersion('Health & Safety v2', '<p>Second</p>');
    $secondReference = EacFormContentProvider::healthSafetyPolicyReference($secondVersion);
    $authoringOptions = app(FormContentRegistry::class)->options(FormContentSlot::QuestionHelp);
    $form = Form::factory()->create();
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'requires_signature' => false,
        'schema' => [[
            'type' => 'checkbox',
            'data' => [
                'key' => DefaultFormDefinitions::HealthSafetyPolicyConsent,
                'label' => 'I agree to the policy.',
                'column_span' => 2,
            ],
        ]],
    ]);
    $schema = $version->schema;
    $schema[0]['data']['help_reference'] = $secondReference;

    $page = livewire(EditFormVersion::class, ['record' => $form->id, 'version' => $version->id])
        ->assertSee('I agree to the policy.');
    $formBuilder = $page->instance()->getSchema('form')->getComponent(
        findComponentUsing: fn (Component $component): bool => $component instanceof Builder
            && $component->getName() === 'schema',
        withActions: false,
        withHidden: true,
    );

    if (! $formBuilder instanceof PreviewBuilder) {
        throw new LogicException('The form builder field did not boot.');
    }

    $itemKey = array_key_first($formBuilder->getRawState());

    $page
        ->mountAction(
            TestAction::make('edit')
                ->arguments(['item' => $itemKey])
                ->schemaComponent('schema', 'form'),
        )
        ->assertMountedActionModalSee('Help source')
        ->setActionData($schema[0]['data'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($authoringOptions)->toHaveKeys([
        EacFormContentProvider::healthSafetyPolicyReference($firstVersion),
        $secondReference,
    ])->and($version->refresh()->schema[0]['data']['help_reference'])->toBe($secondReference);
});

it('compares seeded waiver versions containing repeatable blocks', function (): void {
    Filament::setCurrentPanel('admin');
    $healthSafetyPolicy = LegalDocument::factory()->create(['key' => HealthSafetyPolicy::KEY]);
    $healthSafetyPolicy->publishVersion('Health & Safety v1', '<p>First</p>');
    publishTextMessageUpdatesPolicy();
    $this->seed(StudentWaiverFormSeeder::class);
    $form = Form::query()->where('key', 'student-waiver')->firstOrFail();
    $current = $form->currentVersion()->firstOrFail();
    $draft = $form->createDraftVersionFrom($current, 'Next season');

    $page = livewire(EditFormVersion::class, ['record' => $form->id, 'version' => $draft->id])
        ->assertSee('Medical Waiver')
        ->assertDontSee('Complete this block to preview it.')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $formBuilder = $page->instance()->getSchema('form')->getComponent(
        findComponentUsing: fn (Component $component): bool => $component instanceof Builder
            && $component->getName() === 'schema',
        withActions: false,
        withHidden: true,
    );
    $preview = $page->instance()->getSchema('preview')->getComponent(
        findComponentUsing: fn (Component $component): bool => $component instanceof LivewireSchemaComponent,
        withActions: false,
        withHidden: true,
    );

    if (! $formBuilder instanceof PreviewBuilder || ! $preview instanceof LivewireSchemaComponent) {
        throw new LogicException('The memory-efficient form editor did not boot.');
    }

    expect($formBuilder)->toBeInstanceOf(PreviewBuilder::class)
        ->and($formBuilder->hasBlockPreviews())->toBeTrue()
        ->and($preview)->toBeInstanceOf(LivewireSchemaComponent::class)
        ->and($preview->isLazy())->toBeTrue()
        ->and($preview->getComponent())->toBe(FormVersionPreview::class);

    $comparison = app(FormVersionComparator::class)->compare($current, $draft->refresh());

    expect($comparison['added'])->toBe([])
        ->and($comparison['removed'])->toBe([])
        ->and($comparison['changed'])->toBe([])
        ->and($comparison['moved'])->toBe([]);

    livewire(VersionsRelationManager::class, [
        'ownerRecord' => $form,
        'pageClass' => ViewForm::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make('compare')->table($draft))
        ->assertMountedActionModalSee($current->versionLabel())
        ->assertMountedActionModalSee($draft->versionLabel())
        ->assertMountedActionModalSee('Emergency Contacts')
        ->assertMountedActionModalSee('added')
        ->assertMountedActionModalSee('changed');
});

it('renders a seeded waiver live preview independently from the editor', function (): void {
    LegalDocument::factory()
        ->create(['key' => HealthSafetyPolicy::KEY])
        ->publishVersion('Health & Safety v1', '<p>First</p>');
    publishTextMessageUpdatesPolicy();
    $this->seed(StudentWaiverFormSeeder::class);
    $version = Form::query()->where('key', 'student-waiver')->firstOrFail()->currentVersion;

    livewire(FormVersionPreview::class, [
        'version' => $version,
        'authoringState' => [
            'label' => $version->label,
            'requires_signature' => $version->requires_signature,
            'schema' => $version->schema,
        ],
    ])
        ->assertSee('Consent to Medical Treatment')
        ->assertSee('Emergency Contacts')
        ->assertSee('Signature');
});

/**
 * @param  array<int, array<string, mixed>>  $blocks
 * @return array<string, mixed>
 */
function findSeededFormBlock(array $blocks, string $key): array
{
    foreach ($blocks as $block) {
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        if (($data['key'] ?? null) === $key) {
            return $block;
        }

        if (is_array($data['components'] ?? null)) {
            $match = findSeededFormBlock($data['components'], $key);

            if ($match !== []) {
                return $match;
            }
        }
    }

    return [];
}
