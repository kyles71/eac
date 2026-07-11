<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ManagedBanners;

use App\Filament\Admin\Resources\ManagedBanners\Pages\CreateManagedBanner;
use App\Filament\Admin\Resources\ManagedBanners\Pages\EditManagedBanner;
use App\Filament\Admin\Resources\ManagedBanners\Pages\ListManagedBanners;
use App\Filament\Admin\Resources\ManagedBanners\Schemas\ManagedBannerForm;
use App\Filament\Admin\Resources\ManagedBanners\Tables\ManagedBannersTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\ManagedBanner;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class ManagedBannerResource extends Resource
{
    protected static ?string $model = ManagedBanner::class;

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = AdminNavigation::SettingsBanners;

    protected static ?string $navigationLabel = 'Banners';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return ManagedBannerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ManagedBannersTable::configure($table);
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
            'index' => ListManagedBanners::route('/'),
            'create' => CreateManagedBanner::route('/create'),
            'edit' => EditManagedBanner::route('/{record}/edit'),
        ];
    }
}
