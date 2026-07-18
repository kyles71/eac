<?php

declare(strict_types=1);

namespace App\Console\Commands\Forms;

use App\Forms\Migration\LegacyFormMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forms:legacy-verify')]
#[Description('Verify the converted form graph, projections, contacts, pivots, and relationships')]
final class LegacyFormsVerifyCommand extends Command
{
    public function handle(LegacyFormMigration $migration): int
    {
        $report = $migration->verify();

        $this->table(
            ['Verification', 'Rows'],
            collect($report)->map(fn (int $count, string $name): array => [str($name)->headline(), $count])->values()->all(),
        );
        $this->components->info('Legacy form migration verification passed.');

        return self::SUCCESS;
    }
}
