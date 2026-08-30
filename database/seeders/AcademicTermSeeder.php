<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\AcademicTermService;
use Illuminate\Database\Seeder;

final class AcademicTermSeeder extends Seeder
{
    public function run(AcademicTermService $academicTerms): void
    {
        $academicTerms->sync();
    }
}
