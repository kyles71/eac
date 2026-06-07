<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use Closure;
use Filament\Schemas\Components\View;

final class ProgressiveList
{
    /**
     * @param  Closure(): array<int, array<string, mixed>>  $items
     * @param  Closure(): bool  $hasMore
     * @param  Closure(): bool  $automaticLoading
     */
    public static function make(
        Closure $items,
        Closure $hasMore,
        Closure $automaticLoading,
        string $loadMethod,
        string $itemView,
        string $emptyMessage,
        int $batchSize,
    ): View {
        return View::make('filament.shared.progressive-list')
            ->viewData(fn (): array => [
                'items' => $items(),
                'hasMore' => $hasMore(),
                'automaticLoading' => $automaticLoading(),
                'loadMethod' => $loadMethod,
                'itemView' => $itemView,
                'emptyMessage' => $emptyMessage,
                'batchSize' => $batchSize,
            ]);
    }
}
