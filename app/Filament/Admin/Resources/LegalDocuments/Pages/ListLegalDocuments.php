<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LegalDocuments\Pages;

use App\Filament\Admin\Resources\LegalDocuments\LegalDocumentResource;
use Filament\Resources\Pages\ListRecords;

final class ListLegalDocuments extends ListRecords
{
    protected static string $resource = LegalDocumentResource::class;
}
