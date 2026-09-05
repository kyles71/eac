<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class PayrollReport extends FinanceReportPage
{
    protected static ?string $title = 'Payroll Report';

    protected static ?string $slug = 'reports/finance/payroll';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('course_name')->label('Course Name')->searchable()->sortable()->toggleable(),
                    TextColumn::make('enrollment_count')->label('Number of Enrollments')->numeric()->sortable()->toggleable(),
                    TextColumn::make('event_date')->label('Event Date')->date('l, Y-m-d')->sortable()->toggleable(),
                    TextColumn::make('assigned_instructors')->label('Assigned Instructor(s)')->searchable()->sortable()->toggleable(),
                    TextColumn::make('sub_instructor')->label('Sub Instructor')->searchable()->sortable()->toggleable(),
                    TextColumn::make('sub_reason')->label('Sub Reason')->searchable()->wrap()->toggleable(),
                    TextColumn::make('hours')->numeric(decimalPlaces: 2)->sortable()->toggleable(),
                ])
                ->filters([$this->payrollDateRangeFilter()])
                ->defaultSort('event_date'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::Payroll;
    }
}
