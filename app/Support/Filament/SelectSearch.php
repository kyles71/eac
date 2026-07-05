<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Support\Services\RelationshipJoiner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final class SelectSearch
{
    /**
     * @param  list<string>  $searchColumns
     * @param  array<int, string>|array<string, string>  $orderBy
     */
    public static function relationship(
        Select $select,
        string $name,
        array $searchColumns,
        Closure $labelFromRecord,
        ?Closure $modifyQueryUsing = null,
        array $orderBy = [],
        string $titleAttribute = 'id',
    ): Select {
        return $select
            ->searchable($searchColumns)
            ->relationship(
                name: $name,
                titleAttribute: $titleAttribute,
                modifyQueryUsing: function (Select $component, Builder $query, ?string $search = null) use ($modifyQueryUsing, $orderBy): Builder {
                    if ($modifyQueryUsing !== null) {
                        $query = $component->evaluate($modifyQueryUsing, [
                            'query' => $query,
                            'search' => $search,
                        ]) ?? $query;
                    }

                    self::applyOrderBy($query, $orderBy);

                    return $query;
                },
            )
            ->getOptionLabelFromRecordUsing($labelFromRecord)
            ->getSearchResultsUsing(
                fn (Select $component, string $search): array => self::getRelationshipSearchResults(
                    component: $component,
                    search: $search,
                    searchColumns: $searchColumns,
                    labelFromRecord: $labelFromRecord,
                    modifyQueryUsing: $modifyQueryUsing,
                    orderBy: $orderBy,
                )
            );
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $searchColumns
     * @param  array<int, string>|array<string, string>  $orderBy
     * @return array<int|string, string>
     */
    private static function search(
        Builder $query,
        string $search,
        array $searchColumns,
        Closure $labelFromRecord,
        int $limit = 50,
        array $orderBy = [],
    ): array {
        $terms = str($search)
            ->squish()
            ->explode(' ')
            ->filter()
            ->values();

        if ($terms->isNotEmpty()) {
            foreach ($terms as $term) {
                $query->where(function (Builder $query) use ($searchColumns, $term): void {
                    foreach ($searchColumns as $index => $searchColumn) {
                        $query->{$index === 0 ? 'whereLike' : 'orWhereLike'}(
                            self::qualifyColumn($query, $searchColumn),
                            "%{$term}%",
                        );
                    }
                });
            }
        }

        self::applyOrderBy($query, $orderBy);

        return $query
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Model $record): array => [$record->getKey() => $labelFromRecord($record)])
            ->all();
    }

    /**
     * @param  list<string>  $searchColumns
     * @param  array<int, string>|array<string, string>  $orderBy
     * @return array<int|string, string>
     */
    private static function getRelationshipSearchResults(
        Select $component,
        string $search,
        array $searchColumns,
        Closure $labelFromRecord,
        ?Closure $modifyQueryUsing,
        array $orderBy,
    ): array {
        $relationship = Relation::noConstraints(fn () => $component->getRelationship());

        if ($relationship === null) {
            return [];
        }

        $query = app(RelationshipJoiner::class)->prepareQueryForNoConstraints($relationship);

        if ($modifyQueryUsing !== null) {
            $query = $component->evaluate($modifyQueryUsing, [
                'query' => $query,
                'search' => $search,
            ]) ?? $query;
        }

        return self::search(
            query: $query,
            search: $search,
            searchColumns: $searchColumns,
            labelFromRecord: fn (Model $record): string => $component->evaluate(
                $labelFromRecord,
                namedInjections: [
                    'record' => $record,
                ],
                typedInjections: [
                    Model::class => $record,
                    $record::class => $record,
                ],
            ),
            limit: $component->getOptionsLimit(),
            orderBy: $orderBy,
        );
    }

    /**
     * @param  array<int, string>|array<string, string>  $orderBy
     */
    private static function applyOrderBy(Builder $query, array $orderBy): void
    {
        foreach ($orderBy as $column => $direction) {
            if (is_int($column)) {
                $column = $direction;
                $direction = 'asc';
            }

            $query->orderBy(self::qualifyColumn($query, $column), $direction);
        }
    }

    private static function qualifyColumn(Builder $query, string $column): string
    {
        if (str_contains($column, '.') || str_contains($column, '->')) {
            return $column;
        }

        return $query->qualifyColumn($column);
    }
}
