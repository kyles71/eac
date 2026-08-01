<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\StudentCommunications;

use App\Filament\Admin\Resources\StudentCommunications\Schemas\StudentCommunicationInfolist;
use App\Filament\Admin\Resources\StudentCommunications\Tables\StudentCommunicationsTable;
use App\Models\StudentCommunication;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

final class StudentCommunicationResource extends Resource
{
    protected static ?string $model = StudentCommunication::class;

    protected static ?string $recordTitleAttribute = 'note';

    protected static bool $isGloballySearchable = false;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return StudentCommunicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentCommunicationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [];
    }
}
