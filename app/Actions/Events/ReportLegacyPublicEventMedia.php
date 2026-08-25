<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Models\Event;
use App\Support\MediaDisks;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ReportLegacyPublicEventMedia
{
    public function handle(): int
    {
        $privateDisk = MediaDisks::private();
        $legacyMedia = Media::query()
            ->where('model_type', (new Event())->getMorphClass())
            ->whereIn('collection_name', ['images', 'documents'])
            ->where(function ($query) use ($privateDisk): void {
                $query
                    ->where('disk', '!=', $privateDisk)
                    ->orWhere(function ($query) use ($privateDisk): void {
                        $query
                            ->whereNotNull('conversions_disk')
                            ->where('conversions_disk', '!=', $privateDisk);
                    });
            })
            ->orderBy('id')
            ->get(['id', 'model_id', 'collection_name', 'disk', 'conversions_disk']);

        if ($legacyMedia->isEmpty()) {
            return 0;
        }

        report(new RuntimeException(
            'Event media must be moved manually to the configured private disk. '
            .'Private disk: '.$privateDisk.'. '
            .'Media: '.$legacyMedia
                ->map(fn (Media $media): string => sprintf(
                    '#%d event:%d collection:%s disk:%s conversions:%s',
                    $media->id,
                    $media->model_id,
                    $media->collection_name,
                    $media->disk,
                    $media->conversions_disk ?? 'none',
                ))
                ->join('; ')
        ));

        return $legacyMedia->count();
    }
}
