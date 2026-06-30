<?php

declare(strict_types=1);

namespace App\Support\LegalDocuments;

use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;

final class HealthSafetyPolicy
{
    public const string KEY = 'health_safety_policy';

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
