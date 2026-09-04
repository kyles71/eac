<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BoardItem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BoardItemAttachmentController
{
    public function __invoke(BoardItem $boardItem, Media $media): StreamedResponse
    {
        Gate::authorize('view', $boardItem);

        abort_unless(
            $media->model_type === $boardItem->getMorphClass()
            && (int) $media->model_id === $boardItem->id
            && $media->collection_name === 'attachments',
            404,
        );

        return Storage::disk($media->disk)->download(
            $media->getPathRelativeToRoot(),
            $media->file_name,
        );
    }
}
