<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardQuickLinks;

use App\Filament\Admin\Resources\DashboardQuickLinks\Pages\CreateDashboardQuickLink;
use App\Filament\Admin\Resources\DashboardQuickLinks\Pages\EditDashboardQuickLink;
use App\Filament\Admin\Resources\DashboardQuickLinks\Pages\ListDashboardQuickLinks;
use App\Filament\Admin\Resources\DashboardQuickLinks\Schemas\DashboardQuickLinkForm;
use App\Filament\Admin\Resources\DashboardQuickLinks\Tables\DashboardQuickLinksTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\DashboardQuickLink;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class DashboardQuickLinkResource extends Resource
{
    protected static ?string $model = DashboardQuickLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationLabel = 'Dashboard Quick Links';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return DashboardQuickLinkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DashboardQuickLinksTable::configure($table);
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
            'index' => ListDashboardQuickLinks::route('/'),
            'create' => CreateDashboardQuickLink::route('/create'),
            'edit' => EditDashboardQuickLink::route('/{record}/edit'),
        ];
    }
}
