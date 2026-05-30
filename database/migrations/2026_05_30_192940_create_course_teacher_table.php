<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        DB::table('course_teacher')->insertUsing(
            ['course_id', 'teacher_id', 'created_at', 'updated_at'],
            DB::table('courses')
                ->selectRaw('id, teacher_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP')
                ->whereNotNull('teacher_id')
        );

        Schema::table('courses', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['teacher_id']);
            } else {
                $table->dropForeign('courses_user_id');
            }

            $table->dropColumn('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->foreignId('teacher_id')
                ->nullable()
                ->after('guest_teacher')
                ->constrained(table: 'users', indexName: 'courses_user_id')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE courses
            SET teacher_id = (
                SELECT MIN(course_teacher.teacher_id)
                FROM course_teacher
                WHERE course_teacher.course_id = courses.id
            )
            WHERE EXISTS (
                SELECT 1
                FROM course_teacher
                WHERE course_teacher.course_id = courses.id
            )
            SQL);

        Schema::dropIfExists('course_teacher');
    }
};
