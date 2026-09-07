<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('teacher_assignment_strategy')
                ->default('all_teachers')
                ->after('guest_teacher');
        });

        Schema::table('course_teacher', function (Blueprint $table): void {
            $table->unsignedSmallInteger('rotation_position')
                ->nullable()
                ->after('teacher_id');
            $table->index(['course_id', 'rotation_position']);
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->string('teacher_assignment_mode')
                ->default('custom')
                ->after('course_id');
            $table->unsignedInteger('teacher_rotation_sequence')
                ->nullable()
                ->after('teacher_assignment_mode');
            $table->index(['course_id', 'teacher_assignment_mode', 'start_time'], 'events_teacher_sync_index');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex('events_teacher_sync_index');
            $table->dropColumn(['teacher_assignment_mode', 'teacher_rotation_sequence']);
        });

        Schema::table('course_teacher', function (Blueprint $table): void {
            $table->dropIndex(['course_id', 'rotation_position']);
            $table->dropColumn('rotation_position');
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('teacher_assignment_strategy');
        });
    }
};
