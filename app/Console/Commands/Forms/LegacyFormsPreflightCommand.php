<?php

declare(strict_types=1);

namespace App\Console\Commands\Forms;

use App\Forms\Migration\LegacyFormMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forms:legacy-preflight')]
#[Description('Validate the origin/dev legacy form graph before the forward-only conversion')]
final class LegacyFormsPreflightCommand extends Command
{
    public function handle(LegacyFormMigration $migration): int
    {
        $report = $migration->preflight();

        if (! $report['required']) {
            $this->components->info('The one-time legacy form cutover has already completed; preflight was skipped.');

            return self::SUCCESS;
        }

        if (! $report['source_present']) {
            $this->components->info('No legacy forms table is present; there is nothing to convert.');

            return self::SUCCESS;
        }

        $this->line('Source fingerprint: '.$report['source_fingerprint']);

        $this->table(
            ['Source table', 'Rows'],
            collect($report['counts'])->map(fn (int $count, string $table): array => [$table, $count])->values()->all(),
        );

        if ($report['broadened_phone_values'] !== []) {
            $this->components->warn('Legacy phone values requiring the extension-aware/broadened rule:');

            foreach ($report['broadened_phone_values'] as $phone) {
                $this->line("  {$phone}");
            }
        }

        $this->components->info('Legacy form preflight passed. No destination rows were written.');

        return self::SUCCESS;
    }
}
