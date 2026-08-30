<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class TermEmailList extends EnrollmentReportPage
{
    protected static ?string $title = 'Term Email List';

    protected static ?string $slug = 'reports/enrollment/term-email-list';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('email')->searchable()->sortable()->copyable()->toggleable(),
                    TextColumn::make('sources')->searchable()->wrap()->toggleable(),
                ])
                ->filters([$this->academicTermFilter()])
                ->defaultSort('email'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::TermEmailList;
    }
}
