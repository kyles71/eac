<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadReportExportController
{
    public function __invoke(Request $request, ReportExport $reportExport): StreamedResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($reportExport->user_id === $user->id, 403);
        abort_unless($reportExport->report_key->canView($user), 403);
        abort_unless(
            $reportExport->status === ReportExportStatus::Completed
            && filled($reportExport->path)
            && $reportExport->expires_at !== null
            && ! $reportExport->expires_at->isPast(),
            404,
        );

        return Storage::disk($reportExport->disk)->download(
            (string) $reportExport->path,
            $reportExport->file_name.'.'.$reportExport->format->value,
        );
    }
}
