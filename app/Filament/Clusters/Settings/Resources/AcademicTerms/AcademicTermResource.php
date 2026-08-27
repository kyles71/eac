<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\AcademicTerms;

use App\Enums\CourseSemester;
use App\Filament\Clusters\Settings\Resources\AcademicTerms\Pages\ListAcademicTerms;
use App\Filament\Clusters\Settings\Resources\AcademicTerms\Schemas\AcademicTermForm;
use App\Filament\Clusters\Settings\Resources\AcademicTerms\Tables\AcademicTermsTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\AcademicTerm;
use App\Services\AcademicTermService;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class AcademicTermResource extends Resource
{
    protected static ?string $model = AcademicTerm::class;

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = AdminNavigation::SettingsAcademicTerms;

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function form(Schema $schema): Schema
    {
        return AcademicTermForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AcademicTermsTable::configure($table);
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
            'index' => ListAcademicTerms::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareFormData(array $data): array
    {
        if (! ($data['uses_default_dates'] ?? false)) {
            return $data;
        }

        $semester = $data['semester'] instanceof CourseSemester
            ? $data['semester']
            : CourseSemester::from((string) $data['semester']);

        return [
            ...$data,
            ...app(AcademicTermService::class)->defaultDates($semester, (int) $data['year']),
        ];
    }
}
