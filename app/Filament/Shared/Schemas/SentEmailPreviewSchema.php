<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use FinityLabs\FinMail\Resources\SentEmailResource\Schemas\SentEmailInfolist;

final class SentEmailPreviewSchema
{
    /** @return array<int, Component> */
    public static function schema(): array
    {
        $schema = SentEmailInfolist::schema();

        foreach ($schema as $component) {
            if (! $component instanceof Grid) {
                continue;
            }

            $childComponents = $component->getDefaultChildComponents();

            if (! is_array($childComponents)) {
                continue;
            }

            $component->schema(array_values(array_filter(
                $childComponents,
                static fn (mixed $childComponent): bool => ! ($childComponent instanceof Entry && $childComponent->getName() === 'sender'),
            )));
        }

        return $schema;
    }
}
