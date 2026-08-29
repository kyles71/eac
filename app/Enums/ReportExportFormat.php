<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReportExportFormat: string implements HasLabel
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function getLabel(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'Excel (XLSX)',
        };
    }
}
