<?php

declare(strict_types=1);

namespace App\Console\Commands\Forms;

use App\Actions\Forms\ReconcileRequiredForms;
use App\Models\Student;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forms:reconcile-required')]
#[Description('Reconcile required course forms for every student in bounded chunks')]
final class ReconcileRequiredFormsCommand extends Command
{
    public function handle(ReconcileRequiredForms $reconcileRequiredForms): int
    {
        $count = 0;
        Student::query()->chunkById(100, function ($students) use (&$count, $reconcileRequiredForms): void {
            $students->each(function (Student $student) use (&$count, $reconcileRequiredForms): void {
                $reconcileRequiredForms->handle($student);
                $count++;
            });
        });
        $this->components->info("Reconciled required forms for {$count} students.");

        return self::SUCCESS;
    }
}
