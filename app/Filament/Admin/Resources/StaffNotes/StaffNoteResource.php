<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\StaffNotes;

use App\Filament\Admin\Resources\StaffNotes\Schemas\StaffNoteForm;
use App\Filament\Admin\Resources\StaffNotes\Schemas\StaffNoteInfolist;
use App\Filament\Admin\Resources\StaffNotes\Tables\StaffNotesTable;
use App\Models\StaffNote;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

final class StaffNoteResource extends Resource
{
    protected static ?string $model = StaffNote::class;

    protected static ?string $recordTitleAttribute = 'note';

    protected static bool $isGloballySearchable = false;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return StaffNoteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StaffNoteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffNotesTable::configure($table);
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
