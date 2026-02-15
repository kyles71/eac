<?php

namespace App\Filament\Admin\Resources\Forms;

use App\Filament\Admin\Resources\Forms\Pages\ListForms;
use App\Filament\Admin\Resources\Forms\Pages\ViewForm;
use App\Filament\Admin\Resources\Forms\Schemas\FormForm;
use App\Filament\Admin\Resources\Forms\Schemas\FormInfolist;
use App\Filament\Admin\Resources\Forms\Tables\FormsTable;
use App\Models\Form;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FormResource extends Resource
{
    protected static ?string $model = Form::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return FormForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FormInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FormsTable::configure($table);
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
            'index' => ListForms::route('/'),
            'view' => ViewForm::route('/{record}'),
        ];
    }
}
