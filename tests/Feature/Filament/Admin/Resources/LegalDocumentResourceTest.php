<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\LegalDocuments\Pages\ListLegalDocuments;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('can render the legal documents index page', function () {
    livewire(ListLegalDocuments::class)
        ->assertOk();
});

it('can list legal documents', function () {
    $documents = LegalDocument::factory(3)->create();

    livewire(ListLegalDocuments::class)
        ->loadTable()
        ->assertCanSeeTableRecords($documents);
});

it('can create a legal document', function () {
    livewire(ListLegalDocuments::class)
        ->callAction(TestAction::make(CreateAction::class)->table(), data: [
            'key' => 'checkout_disclosures',
            'name' => 'Checkout Disclosures',
            'description' => 'Reusable checkout disclosure text.',
        ])
        ->assertNotified('Legal document created');

    assertDatabaseHas(LegalDocument::class, [
        'key' => 'checkout_disclosures',
        'name' => 'Checkout Disclosures',
        'description' => 'Reusable checkout disclosure text.',
    ]);
});

it('can update legal document name and description without changing the key', function () {
    $document = LegalDocument::factory()->create([
        'key' => 'student_waiver_terms',
        'name' => 'Payment Plan Terms',
        'description' => 'Old description.',
    ]);

    livewire(ListLegalDocuments::class)
        ->callAction(TestAction::make(EditAction::class)->table($document), data: [
            'name' => 'Payment Plan Terms & Conditions',
            'description' => 'Updated description.',
        ])
        ->assertNotified('Legal document updated');

    $document->refresh();

    expect($document->key)->toBe('student_waiver_terms')
        ->and($document->name)->toBe('Payment Plan Terms & Conditions')
        ->and($document->description)->toBe('Updated description.');
});

it('can publish a new legal document version', function () {
    $document = LegalDocument::factory()->create([
        'name' => 'Payment Plan Terms & Conditions',
    ]);

    LegalDocumentVersion::factory()->create([
        'legal_document_id' => $document->id,
        'version' => 1,
        'title' => 'Original Terms',
    ]);

    livewire(ListLegalDocuments::class)
        ->callAction(TestAction::make('publishVersion')->table($document), data: [
            'title' => 'Updated Terms',
            'content' => '<p>Updated payment plan terms.</p>',
        ])
        ->assertNotified('Document version published');

    assertDatabaseHas(LegalDocumentVersion::class, [
        'legal_document_id' => $document->id,
        'version' => 2,
        'title' => 'Updated Terms',
        'content' => '<p>Updated payment plan terms.</p>',
    ]);
});

it('allows nested rich editor payload updates in the publish version action', function () {
    $document = LegalDocument::factory()->create();
    $richEditorLinkTargetPath = 'mountedActions.0.data.content.content.69.content.0.content.0.content.0.content.1.marks.0.attrs.target';
    $richEditorLinkTargetPathDepth = count(explode('.', $richEditorLinkTargetPath));

    expect($richEditorLinkTargetPathDepth)
        ->toBeGreaterThan(10)
        ->toBeLessThanOrEqual(config('livewire.payload.max_nesting_depth'));

    livewire(ListLegalDocuments::class)
        ->mountAction(TestAction::make('publishVersion')->table($document))
        ->set($richEditorLinkTargetPath, '_blank')
        ->assertSet($richEditorLinkTargetPath, '_blank');
});

it('keeps the publish version rich editor body scrollable inside the modal', function () {
    $document = LegalDocument::factory()->create();

    livewire(ListLegalDocuments::class)
        ->mountAction(TestAction::make('publishVersion')->table($document))
        ->assertSchemaComponentExists(
            'content',
            null,
            fn (RichEditor $component): bool => ($component->getExtraInputAttributes()['style'] ?? null) === 'max-height: min(55vh, 40rem); min-height: 8rem; overflow-y: auto;',
        );
});

it('has required table columns', function (string $column) {
    livewire(ListLegalDocuments::class)
        ->assertTableColumnExists($column);
})->with(['name', 'key', 'latestPublishedVersion.version', 'latestPublishedVersion.published_at']);
