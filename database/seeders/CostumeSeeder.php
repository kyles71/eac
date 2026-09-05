<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Costume;
use Illuminate\Database\Seeder;

final class CostumeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Costume::factory()->count(10)->create();
    }
}
