<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class EmergencyTextsByCourse extends InstructorReportPage
{
    protected static ?string $title = 'Emergency Texts by Course';

    protected static ?string $slug = 'reports/instructor/emergency-texts-by-course';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('dancer_name')->label('Dancer Name')->searchable()->sortable()->toggleable(),
                    TextColumn::make('emergency_contact_name')->label('Emergency Contact Name')->searchable()->sortable()->toggleable(),
                    TextColumn::make('phone_number')->label('Phone Number')->searchable()->sortable()->toggleable(),
                ])
                ->filters([
                    $this->academicTermFilter(defaultToCurrentTerm: false),
                    $this->courseFilter(defaultToFirstCourse: false),
                ])
                ->defaultSort('dancer_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::EmergencyTextsByCourse;
    }
}
