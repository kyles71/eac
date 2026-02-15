<?php

namespace App\Filament\User\Resources\FormUsers;

use App\Enums\FormTypes;
use App\Filament\User\Resources\FormUsers\Pages\ListFormUsers;
use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Filament\User\Resources\FormUsers\Pages\ViewFormUser;
use App\Filament\User\Resources\FormUsers\Schemas\FormUserForm;
use App\Filament\User\Resources\FormUsers\Schemas\FormUserInfolist;
use App\Filament\User\Resources\FormUsers\Tables\FormUsersTable;
use App\Models\FormUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FormUserResource extends Resource
{
    protected static ?string $slug = 'my-forms';

    protected static ?string $model = FormUser::class;

    protected static ?string $modelLabel = 'My Form';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // protected static ?string $recordTitleAttribute = 'form.name';

    public static function form(Schema $schema, ?FormTypes $form_type = null): Schema
    {
        return FormUserForm::configure($schema, $form_type);
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
            'edit' => EditFormUser::route('/{record}/sign'),
        ];
    }
}
