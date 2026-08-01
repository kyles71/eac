<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LegalDocuments;

use App\Filament\Admin\Resources\LegalDocuments\Pages\ListLegalDocuments;
use App\Filament\Admin\Resources\LegalDocuments\Tables\LegalDocumentsTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\LegalDocument;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class LegalDocumentResource extends Resource
{
    protected static ?string $model = LegalDocument::class;

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = AdminNavigation::SettingsLegalDocuments;

    protected static ?string $navigationLabel = 'Legal Documents';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return LegalDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegalDocuments::route('/'),
        ];
    }
}
