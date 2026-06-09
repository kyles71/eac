<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardMessages;

use App\Filament\Admin\Resources\DashboardMessages\Pages\CreateDashboardMessage;
use App\Filament\Admin\Resources\DashboardMessages\Pages\EditDashboardMessage;
use App\Filament\Admin\Resources\DashboardMessages\Pages\ListDashboardMessages;
use App\Filament\Admin\Resources\DashboardMessages\Schemas\DashboardMessageForm;
use App\Filament\Admin\Resources\DashboardMessages\Tables\DashboardMessagesTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\DashboardMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class DashboardMessageResource extends Resource
{
    protected static ?string $model = DashboardMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationLabel = 'Dashboard Messages';

    protected static ?string $recordTitleAttribute = 'message';

    public static function form(Schema $schema): Schema
    {
        return DashboardMessageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DashboardMessagesTable::configure($table);
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
            'index' => ListDashboardMessages::route('/'),
            'create' => CreateDashboardMessage::route('/create'),
            'edit' => EditDashboardMessage::route('/{record}/edit'),
        ];
    }
}
