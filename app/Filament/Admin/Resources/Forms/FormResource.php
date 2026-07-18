<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms;

use App\Filament\Admin\Resources\Forms\Pages\CreateForm;
use App\Filament\Admin\Resources\Forms\Pages\EditForm;
use App\Filament\Admin\Resources\Forms\Pages\EditFormVersion;
use App\Filament\Admin\Resources\Forms\Pages\ListForms;
use App\Filament\Admin\Resources\Forms\Pages\ViewForm;
use App\Filament\Admin\Resources\Forms\Pages\ViewFormAnalytics;
use App\Filament\Admin\Resources\Forms\Pages\ViewResponse;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Form;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\RelationManagers\AnswerGroupsRelationManager;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\RelationManagers\AssignmentsRelationManager;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\RelationManagers\ResponsesRelationManager;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\RelationManagers\VersionsRelationManager;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\Schemas\FormForm;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\Schemas\FormInfolist;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\Tables\FormsTable;

final class FormResource extends Resource
{
    protected static ?string $model = Form::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = AdminNavigation::SettingsForms;

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
            VersionsRelationManager::class,
            AssignmentsRelationManager::class,
            ResponsesRelationManager::class,
            AnswerGroupsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListForms::route('/'),
            'create' => CreateForm::route('/create'),
            'view' => ViewForm::route('/{record}'),
            'edit' => EditForm::route('/{record}/edit'),
            'edit-version' => EditFormVersion::route('/{record}/versions/{version}/edit'),
            'analytics' => ViewFormAnalytics::route('/{record}/analytics'),
            'response' => ViewResponse::route('/{record}/responses/{response}'),
        ];
    }
}
