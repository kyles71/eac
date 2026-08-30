<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\StaffNote;
use Illuminate\Database\Seeder;

final class StaffNoteSeeder extends Seeder
{
    public function run(): void
    {
        StaffNote::factory()->count(10)->create();
    }
}
