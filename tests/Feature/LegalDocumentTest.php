<?php

declare(strict_types=1);

use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use App\Support\LegalDocuments\HealthSafetyPolicy;
use App\Support\LegalDocuments\PortalTerms;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;

it('publishes new versions without mutating the current published terms', function () {
    $document = LegalDocument::factory()->create();

    $initialVersion = LegalDocumentVersion::factory()->create([
        'legal_document_id' => $document->id,
        'version' => 1,
        'title' => 'Initial Terms',
        'content' => '<p>Initial terms.</p>',
    ]);

    $newVersion = $document->publishVersion(
        title: 'Updated Terms',
        content: '<p>Updated terms.</p>',
    );

    expect($newVersion->version)->toBe(2)
        ->and($document->currentVersion()->is($newVersion))->toBeTrue()
        ->and($initialVersion->refresh()->content)->toBe('<p>Initial terms.</p>');
});

it('prevents published legal document versions from being edited', function () {
    $version = LegalDocumentVersion::factory()->create([
        'content' => '<p>Original terms.</p>',
    ]);

    $version->update(['content' => '<p>Mutated terms.</p>']);
})->throws(LogicException::class, 'Published legal document versions are immutable');

it('allows anyone to view and print published portal terms', function () {
    auth()->logout();

    $document = LegalDocument::factory()->create([
        'key' => PortalTerms::KEY,
    ]);

    $version = LegalDocumentVersion::factory()->create([
        'legal_document_id' => $document->id,
        'title' => 'Portal Terms & Conditions',
    ]);

    $this->get(route('legal-documents.versions.show', $version))
        ->assertOk()
        ->assertSee('Print')
        ->assertSee('Portal Terms & Conditions');
});

it('allows anyone to view and print published waiver policy documents', function (string $key, string $title) {
    auth()->logout();

    $document = LegalDocument::factory()->create([
        'key' => $key,
    ]);

    $version = LegalDocumentVersion::factory()->create([
        'legal_document_id' => $document->id,
        'title' => $title,
    ]);

    $this->get(route('legal-documents.versions.show', $version))
        ->assertOk()
        ->assertSee('Print')
        ->assertSee($title);
})->with([
    'health safety policy' => [HealthSafetyPolicy::KEY, 'EAC Health & Safety Policy'],
    'text message updates policy' => [TextMessageUpdatesPolicy::KEY, 'Text Message Updates Policy'],
]);

it('does not publicly expose other legal documents', function () {
    auth()->logout();

    $version = LegalDocumentVersion::factory()->create();

    $this->get(route('legal-documents.versions.show', $version))
        ->assertForbidden();
});
