<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BoardItemComment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BoardItemCommentAttachmentController
{
    public function __invoke(BoardItemComment $boardItemComment, Media $media): StreamedResponse
    {
        Gate::authorize('view', $boardItemComment);

        abort_unless(
            $media->model_type === $boardItemComment->getMorphClass()
            && (int) $media->model_id === $boardItemComment->id
            && $media->collection_name === 'attachments',
            404,
        );

        return Storage::disk($media->disk)->download(
            $media->getPathRelativeToRoot(),
            $media->file_name,
        );
    }
}
