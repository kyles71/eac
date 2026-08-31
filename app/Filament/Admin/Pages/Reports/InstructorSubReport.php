<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class InstructorSubReport extends InstructorReportPage
{
    protected static ?string $title = 'Sub Report';

    protected static ?string $slug = 'reports/instructor/sub-report';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('original_instructor')->label('Original Instructor')->searchable()->sortable()->toggleable(),
                    TextColumn::make('course_name')->label('Course')->searchable()->sortable()->toggleable(),
                    TextColumn::make('event_date')->label('Event Date')->sortable()->toggleable(),
                    TextColumn::make('reason')->searchable()->wrap()->toggleable(),
                    TextColumn::make('substitute_instructor')->label('Sub Instructor Name')->searchable()->sortable()->toggleable(),
                ])
                ->filters([$this->academicTermFilter()])
                ->defaultSort('event_date'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::InstructorSubReport;
    }
}
