<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LegalDocumentAcceptance;
use App\Models\LegalDocumentVersion;
use App\Support\LegalDocuments\PortalTerms;
use Illuminate\Contracts\View\View;

final class LegalDocumentVersionController
{
    public function __invoke(LegalDocumentVersion $legalDocumentVersion): View
    {
        $legalDocumentVersion->loadMissing('document');

        if ($legalDocumentVersion->published_at !== null && $legalDocumentVersion->document?->key === PortalTerms::KEY) {
            return view('legal-documents.version', [
                'version' => $legalDocumentVersion,
            ]);
        }

        $user = auth()->user();

        abort_if($user === null, 403);

        $canViewAsAdmin = $user->can('ViewAny:LegalDocument');
        $hasAcceptedVersion = LegalDocumentAcceptance::query()
            ->where('user_id', $user->id)
            ->where('legal_document_version_id', $legalDocumentVersion->id)
            ->exists();

        abort_if(! $canViewAsAdmin && ! $hasAcceptedVersion, 403);

        return view('legal-documents.version', [
            'version' => $legalDocumentVersion,
        ]);
    }
}
