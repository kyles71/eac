<?php

declare(strict_types=1);

namespace App\Console\Commands\Forms;

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
    public function handle(): int
    {
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
                        '--skip-lock-tables',
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

        $this->components->info("Database snapshot created at [{$path}].");

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

        return $path;
    }
}
