<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlanTemplates;

use App\Filament\Admin\Resources\PaymentPlanTemplates\Pages\CreatePaymentPlanTemplate;
use App\Filament\Admin\Resources\PaymentPlanTemplates\Pages\EditPaymentPlanTemplate;
use App\Filament\Admin\Resources\PaymentPlanTemplates\Pages\ListPaymentPlanTemplates;
use App\Filament\Admin\Resources\PaymentPlanTemplates\Schemas\PaymentPlanTemplateForm;
use App\Filament\Admin\Resources\PaymentPlanTemplates\Tables\PaymentPlanTemplatesTable;
use App\Models\PaymentPlanTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class PaymentPlanTemplateResource extends Resource
{
    protected static ?string $model = PaymentPlanTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static UnitEnum|string|null $navigationGroup = 'Store';

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
            'create' => CreatePaymentPlanTemplate::route('/create'),
            'edit' => EditPaymentPlanTemplate::route('/{record}/edit'),
        ];
    }
}
