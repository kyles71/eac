<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StaffNote;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StaffNoteDocumentController
{
    public function __invoke(StaffNote $staffNote, Media $media): StreamedResponse
    {
        Gate::authorize('view', $staffNote);

        abort_unless(
            $media->model_type === $staffNote->getMorphClass()
            && (int) $media->model_id === $staffNote->id
            && $media->collection_name === 'documents',
            404,
        );

        return Storage::disk($media->disk)->download(
            $media->getPathRelativeToRoot(),
            $media->file_name,
        );
    }
}
