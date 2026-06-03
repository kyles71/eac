<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LegalDocumentAcceptance;
use App\Models\LegalDocumentVersion;
use Illuminate\Contracts\View\View;

final class LegalDocumentVersionController
{
    public function __invoke(LegalDocumentVersion $legalDocumentVersion): View
    {
        $user = auth()->user();

        abort_if($user === null, 403);

        $canViewAsAdmin = $user->can('ViewAny:LegalDocument');
        $hasAcceptedVersion = LegalDocumentAcceptance::query()
            ->where('user_id', $user->id)
            ->where('legal_document_version_id', $legalDocumentVersion->id)
            ->exists();

        abort_if(! $canViewAsAdmin && ! $hasAcceptedVersion, 403);

        $legalDocumentVersion->loadMissing('document');

        return view('legal-documents.version', [
            'version' => $legalDocumentVersion,
        ]);
    }
}
