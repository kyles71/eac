<?php

declare(strict_types=1);

namespace App\Support\LegalDocuments;

use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;

final class PortalTerms
{
    public const string KEY = 'portal_terms';

    public static function document(): ?LegalDocument
    {
        return LegalDocument::query()
            ->where('key', self::KEY)
            ->first();
    }

    public static function currentVersion(): ?LegalDocumentVersion
    {
        return self::document()?->currentVersion();
    }
}
