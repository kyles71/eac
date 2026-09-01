<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('reports:prune-exports')]
#[Description('Delete expired private report export files and records')]
final class PruneReportExports extends Command
{
    public function handle(): int
    {
        $deleted = 0;

        ReportExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->eachById(function (ReportExport $export) use (&$deleted): void {
                if (filled($export->path)) {
                    Storage::disk($export->disk)->delete((string) $export->path);
                }

                $export->delete();
                $deleted++;
            });

        $this->components->info("Expired report exports pruned ({$deleted} deleted).");

        return self::SUCCESS;
    }
}
