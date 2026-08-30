<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CompetitionEmailList extends EnrollmentReportPage
{
    protected static ?string $title = 'Competition Email List';

    protected static ?string $slug = 'reports/enrollment/competition-email-list';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('email')->searchable()->sortable()->copyable()->toggleable(),
                    TextColumn::make('competition_team')
                        ->label('Competition Team')
                        ->searchable()
                        ->sortable()
                        ->wrap()
                        ->toggleable(),
                    TextColumn::make('sources')->searchable()->wrap()->toggleable(),
                ])
                ->filters([$this->competitionSeasonFilter(), $this->competitionTeamFilter()])
                ->defaultSort('email'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::CompetitionEmailList;
    }
}
