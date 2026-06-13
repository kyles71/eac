<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('competition_team_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_team_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('student_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['competition_team_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_team_student');
    }
};
