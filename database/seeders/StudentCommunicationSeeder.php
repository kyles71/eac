<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\StudentCommunication;
use Illuminate\Database\Seeder;

final class StudentCommunicationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        StudentCommunication::factory()->count(10)->create();
    }
}
