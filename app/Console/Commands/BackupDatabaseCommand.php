<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use ZipArchive;

final class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Create an encrypted database backup on private IONOS object storage';

    public function handle(): int
    {
        $password = config('backup.backup.password');

        if (! is_string($password) || mb_trim($password) === '') {
            return $this->failSafely('Database backup aborted because BACKUP_ARCHIVE_PASSWORD is not configured.');
        }

        if (! ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            return $this->failSafely('Database backup aborted because this PHP ZIP build does not support AES-256 encryption.');
        }

        return $this->call('backup:run', [
            '--only-db' => true,
            '--disable-notifications' => true,
        ]);
    }

    private function failSafely(string $message): int
    {
        report(new RuntimeException($message));

        $this->error($message);

        return self::FAILURE;
    }
}
