<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AcademicTermService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('academic-terms:sync')]
#[Description('Ensure current and upcoming academic terms exist from the recurring date defaults')]
final class SyncAcademicTerms extends Command
{
    public function handle(AcademicTermService $academicTerms): int
    {
        $changed = $academicTerms->sync();

        $this->components->info("Academic terms synchronized ({$changed} changed).");

        return self::SUCCESS;
    }
}
