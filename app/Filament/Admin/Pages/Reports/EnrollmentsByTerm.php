<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use App\Models\User;
use App\Services\Reports\EnrollmentReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class EnrollmentsByTerm extends EnrollmentReportPage
{
    protected static ?string $title = 'Enrollments by Term';

    protected static ?string $slug = 'reports/enrollment/enrollments-by-term';

    public function table(Table $table): Table
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $headers = app(EnrollmentReportService::class)
            ->dataset(
                ReportKey::EnrollmentsByTerm,
                $user,
                $this->tableFilters ?? [],
            )
            ->headers;
        $columns = collect($headers)->map(function (string $label, string $key): TextColumn {
            $column = TextColumn::make($key)
                ->label($label)
                ->sortable()
                ->searchable()
                ->toggleable();

            if (str_starts_with($key, 'course_')) {
                $column->alignCenter();
            }

            if (in_array($key, ['medical_waiver', 'media_release'], true)) {
                $column
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'Approved'), $state === 'On File' => 'success',
                        str_contains($state, 'Declined'), $state === 'Expired' => 'danger',
                        default => 'warning',
                    });
            }

            return $column;
        })->values()->all();

        return $this->configureReportTable(
            $table
                ->columns($columns)
                ->filters([$this->academicTermFilter(), $this->courseFilter()])
                ->defaultSort('dancer_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::EnrollmentsByTerm;
    }
}
