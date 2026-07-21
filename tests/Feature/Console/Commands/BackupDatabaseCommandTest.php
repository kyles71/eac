<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Artisan;

it('fails safely and reports when the archive password is missing', function (): void {
    config()->set('backup.backup.password');

    $exceptionHandler = Mockery::mock(ExceptionHandler::class);
    $exceptionHandler
        ->shouldReceive('report')
        ->once()
        ->with(Mockery::on(fn (RuntimeException $exception): bool => str_contains(
            $exception->getMessage(),
            'BACKUP_ARCHIVE_PASSWORD is not configured',
        )));

    $this->app->instance(ExceptionHandler::class, $exceptionHandler);

    $this->artisan('backup:database')
        ->expectsOutput('Database backup aborted because BACKUP_ARCHIVE_PASSWORD is not configured.')
        ->assertFailed();
});

it('delegates database-only backups to spatie and preserves its exit code', function (int $exitCode): void {
    config()->set('backup.backup.password', 'test-backup-password');

    $invocation = new class
    {
        public bool $onlyDatabase = false;

        public bool $notificationsDisabled = false;
    };

    $backupCommand = new class($invocation, $exitCode) extends Command
    {
        protected $signature = 'backup:run {--only-db} {--disable-notifications}';

        public function __construct(
            private readonly object $invocation,
            private readonly int $exitCode,
        ) {
            parent::__construct();
        }

        public function handle(): int
        {
            $this->invocation->onlyDatabase = (bool) $this->option('only-db');
            $this->invocation->notificationsDisabled = (bool) $this->option('disable-notifications');

            return $this->exitCode;
        }
    };

    Artisan::registerCommand($backupCommand);

    $this->artisan('backup:database')
        ->assertExitCode($exitCode);

    expect($invocation->onlyDatabase)->toBeTrue()
        ->and($invocation->notificationsDisabled)->toBeTrue();
})->with([
    'successful backup' => Command::SUCCESS,
    'failed backup' => Command::FAILURE,
]);
