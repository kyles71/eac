<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings;

use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class SettingsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::Tools;

    protected static ?int $navigationSort = AdminNavigation::ToolsSettings;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;
}
