<?php

declare(strict_types=1);

namespace App\Console\Commands\Forms;

use App\Forms\Migration\LegacyCutoverSnapshot;
use App\Forms\Migration\LegacyFormMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

#[Signature('forms:legacy-snapshot')]
#[Description('Create the mandatory database snapshot before the forward-only legacy form conversion')]
final class LegacyFormsSnapshotCommand extends Command
{
    public function handle(LegacyFormMigration $migration, LegacyCutoverSnapshot $snapshot): int
    {
        $report = $migration->preflight();

        if (! $report['required']) {
            $this->components->info('The one-time legacy form cutover has already completed; snapshotting was skipped.');

            return self::SUCCESS;
        }

        if (! $report['source_present']) {
            $this->components->info('No legacy forms table is present; snapshotting was skipped.');

            return self::SUCCESS;
        }

        $connectionName = (string) config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            $this->components->error("Database connection [{$connectionName}] is not configured.");

            return self::FAILURE;
        }

        $directory = storage_path('app/private/backups');
        File::ensureDirectoryExists($directory);
        $timestamp = now()->utc()->format('Ymd-His');
        $driver = (string) ($connection['driver'] ?? '');

        try {
            $path = match ($driver) {
                'sqlite' => $this->snapshotSqlite($connection, $directory, $timestamp),
                'mysql', 'mariadb' => $this->snapshotProcess(
                    [
                        'mysqldump',
                        '--single-transaction',
                        '--quick',
                        '--no-tablespaces',
                        '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                        '--port='.(string) ($connection['port'] ?? 3306),
                        '--user='.(string) ($connection['username'] ?? ''),
                        (string) ($connection['database'] ?? ''),
                    ],
                    ['MYSQL_PWD' => (string) ($connection['password'] ?? '')],
                    "{$directory}/pre-form-builder-{$timestamp}.sql",
                ),
                'pgsql' => $this->snapshotProcess(
                    [
                        'pg_dump',
                        '--format=plain',
                        '--no-owner',
                        '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                        '--port='.(string) ($connection['port'] ?? 5432),
                        '--username='.(string) ($connection['username'] ?? ''),
                        (string) ($connection['database'] ?? ''),
                    ],
                    ['PGPASSWORD' => (string) ($connection['password'] ?? '')],
                    "{$directory}/pre-form-builder-{$timestamp}.sql",
                ),
                default => throw new RuntimeException("Database driver [{$driver}] cannot be snapshotted by this command."),
            };
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $snapshot->record($path, $report['source_fingerprint'], $report['source_manifest']);

        $this->components->info("Database snapshot created at [{$path}].");
        $this->line('SHA-256: '.hash_file('sha256', $path));
        $this->line('Source fingerprint: '.$report['source_fingerprint']);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $connection */
    private function snapshotSqlite(array $connection, string $directory, string $timestamp): string
    {
        $database = (string) ($connection['database'] ?? '');

        if ($database === '' || $database === ':memory:' || ! File::exists($database)) {
            throw new RuntimeException('The configured SQLite database is not a snapshot-capable file.');
        }

        $path = "{$directory}/pre-form-builder-{$timestamp}.sqlite";

        if (! File::copy($database, $path)) {
            throw new RuntimeException('The SQLite database snapshot could not be copied.');
        }

        return $path;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function snapshotProcess(array $command, array $environment, string $path): string
    {
        $output = fopen($path, 'wb');

        if ($output === false) {
            throw new RuntimeException("Database snapshot [{$path}] could not be opened for writing.");
        }

        $process = new Process($command, null, $environment);
        $process->setTimeout(600);
        $process->run(function (string $type, string $buffer) use ($output): void {
            if ($type === Process::OUT) {
                fwrite($output, $buffer);
            }
        });
        fclose($output);

        if (! $process->isSuccessful()) {
            File::delete($path);
            throw new RuntimeException('The database snapshot command failed: '.mb_trim($process->getErrorOutput()));
        }

        if (! File::isFile($path) || File::size($path) === 0) {
            File::delete($path);
            throw new RuntimeException('The database snapshot command produced an empty dump.');
        }

        return $path;
    }
}
