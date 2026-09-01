<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\ReportExportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

final class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public ReportExport $reportExport) {}

    public function handle(ReportExportService $exports): void
    {
        $this->reportExport->update([
            'status' => ReportExportStatus::Processing,
            'error' => null,
            'failed_at' => null,
        ]);

        $user = $this->reportExport->user;

        if (! $user instanceof User) {
            return;
        }

        $path = $exports->generate($this->reportExport, $user);
        $this->reportExport->update([
            'status' => ReportExportStatus::Completed,
            'path' => $path,
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $notification = Notification::make()
            ->title($this->reportExport->report_key->label().' export is ready')
            ->body('The private download is available for seven days.')
            ->success()
            ->actions([
                Action::make('download')
                    ->label('Download')
                    ->url(URL::temporarySignedRoute(
                        'admin.report-exports.download',
                        now()->addDays(7),
                        ['reportExport' => $this->reportExport],
                        absolute: false,
                    )),
            ]);

        $this->sendNotification($notification, $user);
    }

    public function failed(?Throwable $exception): void
    {
        $this->reportExport->update([
            'status' => ReportExportStatus::Failed,
            'error' => Str::limit($exception?->getMessage() ?? 'Unknown export error', 2000),
            'failed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $user = $this->reportExport->user;

        if (! $user instanceof User) {
            return;
        }

        $this->sendNotification(
            Notification::make()
                ->title($this->reportExport->report_key->label().' export failed')
                ->body('Narrow the report filters and try again. If the problem continues, contact an administrator.')
                ->danger(),
            $user,
        );
    }

    private function sendNotification(Notification $notification, User $user): void
    {
        if (config('queue.default') === 'sync') {
            $notification->persistent()->send();

            return;
        }

        $notification->sendToDatabase($user, isEventDispatched: true);
    }
}
