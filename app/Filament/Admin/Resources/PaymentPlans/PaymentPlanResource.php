<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlans;

use App\Filament\Admin\Resources\PaymentPlans\Pages\ListPaymentPlans;
use App\Filament\Admin\Resources\PaymentPlans\Pages\ViewPaymentPlan;
use App\Filament\Admin\Resources\PaymentPlans\Schemas\PaymentPlanInfolist;
use App\Filament\Admin\Resources\PaymentPlans\Tables\PaymentPlansTable;
use App\Models\PaymentPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class PaymentPlanResource extends Resource
{
    protected static ?string $model = PaymentPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static UnitEnum|string|null $navigationGroup = 'Store';

    protected static ?string $navigationLabel = 'Payment Plans';

    public static function infolist(Schema $schema): Schema
    {
        return PaymentPlanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentPlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentPlans::route('/'),
            'view' => ViewPaymentPlan::route('/{record}'),
        ];
    }
}
