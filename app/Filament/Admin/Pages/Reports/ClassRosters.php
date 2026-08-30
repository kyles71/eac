<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ClassRosters extends InstructorReportPage
{
    protected static ?string $title = 'Class Rosters';

    protected static ?string $slug = 'reports/instructor/class-rosters';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('dancer_name')->label('Dancer Name')->searchable()->sortable()->toggleable(),
                    TextColumn::make('media_release')
                        ->label('Media Release')
                        ->badge()
                        ->color(fn (string $state): string => match (true) {
                            str_contains($state, 'Approved') => 'success',
                            str_contains($state, 'Declined') => 'danger',
                            default => 'warning',
                        })
                        ->sortable()
                        ->toggleable(),
                ])
                ->filters([$this->academicTermFilter(), $this->courseFilter()])
                ->defaultSort('dancer_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::ClassRosters;
    }
}
