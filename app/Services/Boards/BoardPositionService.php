<?php

declare(strict_types=1);

namespace App\Services\Boards;

use App\Models\BoardItem;
use App\Models\BoardStage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Relaticle\Flowforge\Services\DecimalPosition;

final class BoardPositionService
{
    public function move(
        BoardItem $item,
        BoardStage $targetStage,
        ?string $afterCardId,
        ?string $beforeCardId,
    ): string {
        $lockedStage = BoardStage::query()
            ->whereKey($targetStage->id)
            ->where('board_id', $item->board_id)
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->first();

        if ($lockedStage === null) {
            throw new InvalidArgumentException('The selected stage is not available on this board.');
        }

        $item->update(['position' => null]);

        $positionedItems = $this->positionedItems($item, $lockedStage);
        [$afterPosition, $beforePosition] = $this->resolveBounds(
            $positionedItems,
            $afterCardId,
            $beforeCardId,
        );

        if ($afterPosition !== null
            && $beforePosition !== null
            && DecimalPosition::needsRebalancing($afterPosition, $beforePosition)) {
            $this->rebalance($positionedItems);
            [$afterPosition, $beforePosition] = $this->resolveBounds(
                $positionedItems,
                $afterCardId,
                $beforeCardId,
            );
        }

        $newPosition = DecimalPosition::calculate($afterPosition, $beforePosition);

        $item->update([
            'board_stage_id' => $lockedStage->id,
            'position' => $newPosition,
        ]);

        return $newPosition;
    }

    /** @return Collection<int, BoardItem> */
    private function positionedItems(BoardItem $item, BoardStage $targetStage): Collection
    {
        return BoardItem::query()
            ->where('board_id', $item->board_id)
            ->where('board_stage_id', $targetStage->id)
            ->whereKeyNot($item->id)
            ->whereNotNull('position')
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Resolve the browser's potentially stale anchors to a currently adjacent,
     * unoccupied interval in the destination stage.
     *
     * @param  Collection<int, BoardItem>  $positionedItems
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveBounds(
        Collection $positionedItems,
        ?string $afterCardId,
        ?string $beforeCardId,
    ): array {
        $afterIndex = $this->activeItemIndex($positionedItems, $afterCardId);
        $beforeIndex = $this->activeItemIndex($positionedItems, $beforeCardId);

        if ($afterIndex !== null && $beforeIndex !== null) {
            $lowerIndex = min($afterIndex, $beforeIndex);

            return $this->boundsAt($positionedItems, $lowerIndex, $lowerIndex + 1);
        }

        if ($afterIndex !== null) {
            return $this->boundsAt($positionedItems, $afterIndex, $afterIndex + 1);
        }

        if ($beforeIndex !== null) {
            return $this->boundsAt($positionedItems, $beforeIndex - 1, $beforeIndex);
        }

        return $this->boundsAt($positionedItems, $positionedItems->count() - 1, null);
    }

    /** @param Collection<int, BoardItem> $positionedItems */
    private function activeItemIndex(Collection $positionedItems, ?string $cardId): ?int
    {
        if ($cardId === null) {
            return null;
        }

        $index = $positionedItems->search(
            fn (BoardItem $candidate): bool => $candidate->archived_at === null
                && (string) $candidate->getKey() === $cardId,
        );

        return $index === false ? null : (int) $index;
    }

    /**
     * @param  Collection<int, BoardItem>  $positionedItems
     * @return array{0: ?string, 1: ?string}
     */
    private function boundsAt(Collection $positionedItems, ?int $afterIndex, ?int $beforeIndex): array
    {
        $afterPosition = $afterIndex !== null && $afterIndex >= 0
            ? $positionedItems->get($afterIndex)?->position
            : null;
        $beforePosition = $beforeIndex !== null
            ? $positionedItems->get($beforeIndex)?->position
            : null;

        return [
            $afterPosition !== null ? DecimalPosition::normalize($afterPosition) : null,
            $beforePosition !== null ? DecimalPosition::normalize($beforePosition) : null,
        ];
    }

    /** @param Collection<int, BoardItem> $positionedItems */
    private function rebalance(Collection $positionedItems): void
    {
        if ($positionedItems->isEmpty()) {
            return;
        }

        DB::table('board_items')
            ->whereIn('id', $positionedItems->modelKeys())
            ->update(['position' => null]);

        $positions = DecimalPosition::generateSequence($positionedItems->count());

        foreach ($positionedItems as $index => $positionedItem) {
            $positionedItem->update(['position' => $positions[$index]]);
        }
    }
}
