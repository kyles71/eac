<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recurring_private_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('lesson_price');
            $table->string('status')->default('Active');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['student_id', 'status']);
        });

        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropForeign(['student_id']);
            $table->foreign('student_id')->references('id')->on('students')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_private_lessons');

        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropForeign(['student_id']);
            $table->foreign('student_id')->references('id')->on('students')->nullOnDelete();
        });
    }
};
