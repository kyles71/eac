<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('course_teacher', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained(table: 'users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_teacher');
    }
};
