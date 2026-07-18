<?php

declare(strict_types=1);

namespace App\Filament\Shared\Forms\Components;

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Schemas\Schema;
use LogicException;

final class PreviewBuilder extends Builder
{
    /** @return array<Schema> */
    public function getItems(): array
    {
        if (! $this->hasBlockPreviews()) {
            return parent::getItems();
        }

        return collect($this->getRawState())
            ->filter(fn (array $itemData): bool => filled($itemData['type'] ?? null) && $this->hasBlock($itemData['type']))
            ->map(function (array $itemData, int|string $itemIndex): Schema {
                $block = $this->getBlock($itemData['type']);

                if (! $block instanceof Block) {
                    throw new LogicException("The [{$itemData['type']}] builder block is not registered.");
                }

                return Schema::make($this->getLivewire())
                    ->parentComponent($block)
                    ->components(fn (): array => $block
                        ->getChildSchema()
                        ->statePath("{$itemIndex}.data")
                        ->constantState($itemData['data'] ?? [])
                        ->inlineLabel(false)
                        ->getClone()
                        ->getComponents(withHidden: true))
                    ->statePath("{$itemIndex}.data")
                    ->constantState($itemData['data'] ?? [])
                    ->inlineLabel(false);
            })
            ->all();
    }
}
