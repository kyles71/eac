<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('substitute_teacher_id')
                ->nullable()
                ->after('course_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('substitute_needed_at')
                ->nullable()
                ->after('substitute_teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('substitute_teacher_id');
            $table->dropColumn('substitute_needed_at');
        });
    }
};
