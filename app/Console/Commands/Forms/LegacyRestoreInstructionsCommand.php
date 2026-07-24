<?php

declare(strict_types=1);

namespace App\Console\Commands\Forms;

use App\Forms\Migration\LegacyCutoverSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('forms:legacy-restore-instructions')]
#[Description('Print the exact snapshot and manual restore command for a failed legacy form cutover')]
final class LegacyRestoreInstructionsCommand extends Command
{
    public function handle(LegacyCutoverSnapshot $snapshot): int
    {
        $manifestPath = $snapshot->manifestPath();

        if (! File::isFile($manifestPath)) {
            $this->components->error("No cutover snapshot manifest exists at [{$manifestPath}].");

            return self::FAILURE;
        }

        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $path = is_array($manifest) ? $manifest['snapshot_path'] ?? null : null;

        if (! is_string($path)) {
            $this->components->error("The cutover snapshot manifest [{$manifestPath}] is invalid.");

            return self::FAILURE;
        }

        $connectionName = (string) config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            $this->components->error("Database connection [{$connectionName}] is not configured.");

            return self::FAILURE;
        }

        $this->components->warn('Keep maintenance mode enabled. Do not run artisan up.');
        $this->line("Snapshot: {$path}");
        $this->line('SHA-256: '.($manifest['snapshot_sha256'] ?? 'unknown'));
        $this->line('Restore only after explicit approval:');
        $this->line(match ($connection['driver'] ?? null) {
            'mysql', 'mariadb' => sprintf(
                "MYSQL_PWD='<database password>' mysql --host=%s --port=%s --user=%s %s < %s",
                escapeshellarg((string) ($connection['host'] ?? '127.0.0.1')),
                escapeshellarg((string) ($connection['port'] ?? 3306)),
                escapeshellarg((string) ($connection['username'] ?? '')),
                escapeshellarg((string) ($connection['database'] ?? '')),
                escapeshellarg($path),
            ),
            'pgsql' => sprintf(
                "PGPASSWORD='<database password>' psql --host=%s --port=%s --username=%s --dbname=%s --file=%s",
                escapeshellarg((string) ($connection['host'] ?? '127.0.0.1')),
                escapeshellarg((string) ($connection['port'] ?? 5432)),
                escapeshellarg((string) ($connection['username'] ?? '')),
                escapeshellarg((string) ($connection['database'] ?? '')),
                escapeshellarg($path),
            ),
            'sqlite' => 'cp '.escapeshellarg($path).' '.escapeshellarg((string) ($connection['database'] ?? '')),
            default => 'No automatic restore command is available for this database driver.',
        });

        return self::SUCCESS;
    }
}
