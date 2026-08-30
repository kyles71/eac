<?php

declare(strict_types=1);

use App\Enums\StoreView;
use App\Filament\User\Pages\Auth\Register;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use App\Models\User;
use App\Support\LegalDocuments\PortalTerms;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
    auth()->logout();
});

it('hides the terms agreement when portal terms do not exist', function () {
    livewire(Register::class)
        ->assertDontSee('I agree to the terms and conditions');
});

it('allows registration without a terms agreement when portal terms do not exist', function () {
    livewire(Register::class)
        ->fillForm([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'register@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    assertDatabaseHas(User::class, [
        'email' => 'register@example.com',
        'store_view' => StoreView::Cards->value,
    ]);
});

it('shows a printable portal terms link when portal terms are published', function () {
    $document = LegalDocument::factory()->create([
        'key' => PortalTerms::KEY,
    ]);

    $version = LegalDocumentVersion::factory()->create([
        'legal_document_id' => $document->id,
    ]);

    livewire(Register::class)
        ->assertSee('I agree to the terms and conditions')
        ->assertSee('View and print the terms and conditions')
        ->assertSee(route('legal-documents.versions.show', $version), false)
        ->assertSee('class="fi-link fi-size-sm fi-color fi-color-primary fi-text-color-600 dark:fi-text-color-400"', false)
        ->assertSee('target="_blank"', false);
});

it('shows the terms agreement without a link when portal terms have no published version', function () {
    LegalDocument::factory()->create([
        'key' => PortalTerms::KEY,
    ]);

    livewire(Register::class)
        ->assertSee('I agree to the terms and conditions')
        ->assertDontSee('View and print the terms and conditions');
});
