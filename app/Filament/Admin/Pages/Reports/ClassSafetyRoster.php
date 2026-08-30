<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use App\Services\Reports\InstructorReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ClassSafetyRoster extends InstructorReportPage
{
    protected static ?string $title = 'Class Safety Roster';

    protected static ?string $slug = 'reports/instructor/class-safety-roster';

    public function table(Table $table): Table
    {
        $headers = app(InstructorReportService::class)
            ->dataset(ReportKey::ClassSafetyRoster, $this->authenticatedUser(), $this->tableFilters ?? [])
            ->headers;
        $columns = collect($headers)
            ->map(fn (string $label, string $key): TextColumn => TextColumn::make($key)
                ->label($label)
                ->searchable()
                ->sortable()
                ->wrap()
                ->toggleable())
            ->values()
            ->all();

        return $this->configureReportTable(
            $table
                ->columns($columns)
                ->filters([$this->academicTermFilter(), $this->courseFilter()])
                ->defaultSort('dancer_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::ClassSafetyRoster;
    }
}
