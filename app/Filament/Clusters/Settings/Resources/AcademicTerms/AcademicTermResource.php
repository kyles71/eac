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
use Illuminate\Validation\ValidationException;

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
    public static function prepareFormData(array $data, ?AcademicTerm $record = null): array
    {
        if (! ($data['uses_default_dates'] ?? false)) {
            return $data;
        }

        $semesterValue = $data['semester'] ?? $record?->semester;
        $semester = $semesterValue instanceof CourseSemester
            ? $semesterValue
            : (is_string($semesterValue) ? CourseSemester::tryFrom($semesterValue) : null);
        $year = filter_var($data['year'] ?? $record?->year, FILTER_VALIDATE_INT);

        if (! $semester instanceof CourseSemester || $year === false) {
            throw ValidationException::withMessages([
                'semester' => 'A valid semester and calendar year are required to use recurring default dates.',
            ]);
        }

        return [
            ...$data,
            ...app(AcademicTermService::class)->defaultDates($semester, $year),
        ];
    }
}
