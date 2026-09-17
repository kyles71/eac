<?php

declare(strict_types=1);

namespace App\Console\Commands\Forms;

use App\Forms\Migration\LegacyFormMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forms:legacy-cutover-required')]
#[Description('Exit successfully only while the one-time legacy form cutover migration is pending')]
final class LegacyCutoverRequiredCommand extends Command
{
    public function handle(LegacyFormMigration $migration): int
    {
        $required = $migration->isRequired();

        if (! $this->option('quiet')) {
            $this->components->info($required
                ? 'The legacy form cutover migration is pending.'
                : 'The legacy form cutover migration has already completed.');
        }

        return $required ? self::SUCCESS : self::FAILURE;
    }
}
