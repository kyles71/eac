<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\ReportExportFormat;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;

final readonly class ReportExportService
{
    public const int MAX_ROWS = 50_000;

    public function __construct(private ReportDatasetResolverService $datasets) {}

    public function generate(ReportExport $export, User $user): string
    {
        abort_unless($export->report_key->canView($user), 403);

        $state = $export->state;
        $filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];
        $search = is_string($state['search'] ?? null) ? $state['search'] : null;
        $sort = is_string($state['sort'] ?? null) ? $state['sort'] : null;
        $columns = is_array($state['columns'] ?? null)
            ? array_values(array_filter($state['columns'], is_string(...)))
            : [];
        $dataset = $this->datasets->dataset($export->report_key, $user, $filters);
        $data = $dataset->exportData($columns, $search, $sort);

        if (count($data['rows']) > self::MAX_ROWS) {
            throw new RuntimeException('The report exceeds the 50,000 row export limit. Narrow the filters and try again.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'eac-report-export-');

        if ($temporaryPath === false) {
            throw new RuntimeException('A temporary report export file could not be created.');
        }

        $writer = $this->writer($export->format);
        $stream = null;

        try {
            $writer->openToFile($temporaryPath);
            $writer->addRow(Row::fromValues(array_values($data['headers'])));

            foreach ($data['rows'] as $row) {
                $writer->addRow(Row::fromValues(array_map(
                    fn (mixed $value): bool|float|int|string|null => $this->exportValue($value, $export->format),
                    array_values($row),
                )));
            }

            $writer->close();

            $path = "report-exports/{$export->id}/{$export->file_name}.{$export->format->value}";
            $stream = fopen($temporaryPath, 'rb');

            if ($stream === false || ! Storage::disk($export->disk)->put($path, $stream, 'private')) {
                throw new RuntimeException('The generated report could not be stored.');
            }

            $export->update(['total_rows' => count($data['rows'])]);

            return $path;
        } finally {
            $writer->close();

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function writer(ReportExportFormat $format): WriterInterface
    {
        return match ($format) {
            ReportExportFormat::Csv => new CsvWriter,
            ReportExportFormat::Xlsx => new XlsxWriter,
        };
    }

    private function exportValue(mixed $value, ReportExportFormat $format): bool|float|int|string|null
    {
        if (! is_bool($value) && ! is_float($value) && ! is_int($value) && ! is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        if ($format !== ReportExportFormat::Csv || ! is_string($value)) {
            return $value;
        }

        $firstMeaningfulCharacter = mb_ltrim($value, " \t\n\r\0\x0B")[0] ?? null;

        return in_array($firstMeaningfulCharacter, ['=', '+', '-', '@'], true)
            ? "'{$value}"
            : $value;
    }
}
