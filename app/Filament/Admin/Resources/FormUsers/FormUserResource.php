<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FormUsers;

use App\Filament\Admin\Resources\FormUsers\Pages\ListFormUsers;
use App\Filament\Admin\Resources\FormUsers\Pages\ViewFormUser;
use App\Filament\Admin\Resources\FormUsers\Schemas\FormUserForm;
use App\Filament\Admin\Resources\FormUsers\Schemas\FormUserInfolist;
use App\Filament\Admin\Resources\FormUsers\Tables\FormUsersTable;
use App\Models\FormUser;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class FormUserResource extends Resource
{
    protected static ?string $slug = 'user-forms';

    protected static ?string $model = FormUser::class;

    protected static ?string $modelLabel = 'Form Assignment';

    protected static ?string $pluralModelLabel = 'Form Assignments';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::PeopleAndAccess;

    protected static ?int $navigationSort = AdminNavigation::PeopleFormAssignments;

    protected static ?string $navigationLabel = 'Form Assignments';

    public static function form(Schema $schema): Schema
    {
        return FormUserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FormUserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FormUsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFormUsers::route('/'),
            'view' => ViewFormUser::route('/{record}'),
        ];
    }
}
