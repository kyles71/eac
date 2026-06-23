<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\RelationManagers;

use App\Filament\Admin\Resources\CreditGrants\CreditGrantResource;
use App\Filament\Admin\Resources\CreditGrants\Tables\CreditGrantsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

final class CreditGrantsRelationManager extends RelationManager
{
    protected static string $relationship = 'creditGrants';

    protected static ?string $relatedResource = CreditGrantResource::class;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return CreditGrantsTable::configure($table)
            ->heading('Store Credit');
    }
}
