<?php

declare(strict_types=1);

namespace App\Console\Commands\Forms;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forms:legacy-verify')]
#[Description('Explain where one-time legacy form cutover verification occurs')]
final class LegacyFormsVerifyCommand extends Command
{
    public function handle(): int
    {
        $this->components->info('Legacy cutover verification is embedded in the one-time migration and is not repeated after deployment.');

        return self::SUCCESS;
    }
}
