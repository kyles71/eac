<?php

declare(strict_types=1);

namespace App\Data\Reports;

use Illuminate\Support\Arr;

final readonly class ReportDataset
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<array<string, bool|float|int|string|null>>  $rows
     * @param  list<array<string, bool|float|int|string|null>>  $footerRows
     */
    public function __construct(
        public array $headers,
        public array $rows,
        public array $footerRows = [],
    ) {}

    /** @return list<array<string, bool|float|int|string|null>> */
    public function rowsFor(?string $search = null, ?string $sort = null): array
    {
        $rows = collect($this->rows);

        if (filled($search)) {
            $needle = mb_strtolower(mb_trim((string) $search));
            $rows = $rows->filter(function (array $row) use ($needle): bool {
                return collect($row)
                    ->filter(fn (mixed $value): bool => is_scalar($value))
                    ->contains(fn (mixed $value): bool => str_contains(
                        mb_strtolower((string) $value),
                        $needle,
                    ));
            });
        }

        [$sortColumn, $sortDirection] = $this->parseSort($sort);

        if ($sortColumn !== null && array_key_exists($sortColumn, $this->headers)) {
            $rows = $rows->sortBy(
                fn (array $row): string => mb_strtolower((string) Arr::get($row, $sortColumn, '')),
                SORT_NATURAL,
                $sortDirection === 'desc',
            );
        }

        return $rows->values()->all();
    }

    /**
     * @param  list<string>  $columns
     * @return array{headers: array<string, string>, rows: list<array<string, bool|float|int|string|null>>}
     */
    public function exportData(array $columns, ?string $search = null, ?string $sort = null): array
    {
        $columns = array_values(array_filter(
            $columns,
            fn (string $column): bool => array_key_exists($column, $this->headers),
        ));
        $columns = $columns === [] ? array_keys($this->headers) : $columns;
        $headers = collect($this->headers)->only($columns)->all();
        $rows = collect($this->rowsFor($search, $sort))
            ->concat($this->footerRows)
            ->map(fn (array $row): array => collect($headers)
                ->mapWithKeys(fn (string $label, string $key): array => [$key => Arr::get($row, $key)])
                ->all())
            ->values()
            ->all();

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @return array{?string, ?string} */
    private function parseSort(?string $sort): array
    {
        if (blank($sort)) {
            return [null, null];
        }

        $parts = explode(':', $sort, 2);
        $direction = ($parts[1] ?? null) === 'desc' ? 'desc' : 'asc';

        return [$parts[0], $direction];
    }
}
