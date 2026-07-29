<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlanTemplates;

use App\Filament\Admin\Resources\PaymentPlanTemplates\Pages\ListPaymentPlanTemplates;
use App\Filament\Admin\Resources\PaymentPlanTemplates\Schemas\PaymentPlanTemplateForm;
use App\Filament\Admin\Resources\PaymentPlanTemplates\Tables\PaymentPlanTemplatesTable;
use App\Models\PaymentPlanTemplate;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class PaymentPlanTemplateResource extends Resource
{
    protected static ?string $model = PaymentPlanTemplate::class;

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::Storefront;

    protected static ?int $navigationSort = AdminNavigation::StorePaymentPlanTemplates;

    protected static ?string $navigationLabel = 'Plan Templates';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentPlanTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentPlanTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentPlanTemplates::route('/'),
        ];
    }
}
