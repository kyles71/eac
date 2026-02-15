<?php

namespace App\Filament\Admin\Resources\FormUsers;

use App\Filament\Admin\Resources\FormUsers\Pages\ListFormUsers;
use App\Filament\Admin\Resources\FormUsers\Pages\ViewFormUser;
use App\Filament\Admin\Resources\FormUsers\Schemas\FormUserForm;
use App\Filament\Admin\Resources\FormUsers\Schemas\FormUserInfolist;
use App\Filament\Admin\Resources\FormUsers\Tables\FormUsersTable;
use App\Models\FormUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FormUserResource extends Resource
{
    protected static ?string $slug = 'user-forms';

    protected static ?string $model = FormUser::class;

    protected static ?string $modelLabel = 'User Form';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

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
