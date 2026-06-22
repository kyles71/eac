<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\LegalDocuments\Pages\ListLegalDocuments;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

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

it('does not expose create or edit actions', function () {
    $document = LegalDocument::factory()->create();

    livewire(ListLegalDocuments::class)
        ->assertActionDoesNotExist(TestAction::make('create')->table())
        ->assertActionDoesNotExist(TestAction::make('edit')->table($document));
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

it('requires publish permission to publish a version', function () {
    $document = LegalDocument::factory()->create();
    $user = App\Models\User::factory()->create();
    $user->givePermissionTo('ViewAny:LegalDocument');

    $this->actingAs($user);

    livewire(ListLegalDocuments::class)
        ->assertActionHidden(TestAction::make('publishVersion')->table($document));
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
    $globalTheme = file_get_contents(resource_path('css/filament/global-theme.css'));

    expect($globalTheme)
        ->toContain('.fi-fo-rich-editor-content')
        ->toContain('flex-basis: auto')
        ->toContain('min-height: 8rem')
        ->toContain('max-height: min(55vh, 40rem)')
        ->toContain('overflow-y: auto');
});

it('has required table columns', function (string $column) {
    livewire(ListLegalDocuments::class)
        ->assertTableColumnExists($column);
})->with(['name', 'key', 'latestPublishedVersion.version', 'latestPublishedVersion.published_at']);
