<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Enums\BoardItemPriority;
use Illuminate\Database\Eloquent\Collection;
use Relaticle\Flowforge\Board;

final class BoardWorkspace extends Board
{
    /**
     * Return cards in the workspace's fixed priority and due-date order.
     *
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function getBoardRecords(string $columnId): Collection
    {
        $query = $this->getQuery();

        if ($query === null) {
            return new Collection;
        }

        $columnField = $this->getColumnIdentifierAttribute();
        $livewire = $this->getLivewire();
        $limit = property_exists($livewire, 'columnCardLimits')
            ? ($livewire->columnCardLimits[$columnId] ?? $this->getCardsPerColumn())
            : $this->getCardsPerColumn();
        $query = (clone $query)->where($columnField, $columnId);

        if ($livewire->getTable()->isFilterable() || $livewire->hasTableSearch()) {
            $query = (clone $livewire->getFilteredTableQuery())->where($columnField, $columnId);
        }

        return $query
            ->reorder()
            ->orderByRaw('CASE WHEN priority = ? THEN 0 ELSE 1 END', [BoardItemPriority::Urgent->value])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('created_at')
            ->orderBy($query->getModel()->getKeyName())
            ->limit($limit)
            ->get();
    }
}
